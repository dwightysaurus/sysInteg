-- =====================================================================
-- University Online Student Registration Portal - Database
-- ITEC 116 Final Project  |  Back-end: Registration Portal
--
-- Import this file in phpMyAdmin (XAMPP) or run:
--   mysql -u root < database/registration_portal.sql
-- It drops and recreates the `registration_portal` database.
-- =====================================================================

SET NAMES utf8mb4;

DROP DATABASE IF EXISTS registration_portal;
CREATE DATABASE registration_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE registration_portal;

-- ---------------------------------------------------------------------
-- Users and roles (student, registrar, cashier)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  first_name    VARCHAR(80)  NOT NULL,
  last_name     VARCHAR(80)  NOT NULL,
  role          ENUM('student','registrar','cashier') NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE students (
  user_id     INT UNSIGNED PRIMARY KEY,
  student_no  VARCHAR(20) NOT NULL UNIQUE,
  program     VARCHAR(20) NOT NULL,
  year_level  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Terms and settings
-- ---------------------------------------------------------------------
CREATE TABLE terms (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(50) NOT NULL UNIQUE,      -- e.g. "1st Sem 2026-2027"
  is_current  TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE settings (
  setting_key   VARCHAR(50) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Courses and sections (managed by the registrar)
-- ---------------------------------------------------------------------
CREATE TABLE courses (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code     VARCHAR(20)  NOT NULL UNIQUE,         -- e.g. "ITEC 101"
  title    VARCHAR(150) NOT NULL,
  units    TINYINT UNSIGNED NOT NULL,
  has_lab  TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE sections (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id     INT UNSIGNED NOT NULL,
  term_id       INT UNSIGNED NOT NULL,
  section_code  VARCHAR(20) NOT NULL,            -- e.g. "BSIT 3-1"
  schedule      VARCHAR(60) NOT NULL,            -- e.g. "MWF 8:00–9:00"
  capacity      SMALLINT UNSIGNED NOT NULL,
  enrolled      SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- slots taken by submitted enrollments
  is_open       TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_section (course_id, term_id, section_code),
  CONSTRAINT fk_sections_course FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_sections_term   FOREIGN KEY (term_id)   REFERENCES terms(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Enrollments
--   status          draft = student is still selecting subjects
--                   submitted = locked, sent (or queued) to Cashiering
--   sync_status     not_sent / synced / failed  (link to Cashiering System)
--   payment_status  pending until the cashier records payment, then paid
-- ---------------------------------------------------------------------
CREATE TABLE enrollments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference_no    VARCHAR(30) NULL UNIQUE,       -- e.g. "EN-2026-000001", set on submit
  student_id      INT UNSIGNED NOT NULL,
  term_id         INT UNSIGNED NOT NULL,
  status          ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
  total_units     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sync_status     ENUM('not_sent','synced','failed') NOT NULL DEFAULT 'not_sent',
  sync_attempts   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_sync_error VARCHAR(255) NULL,
  last_sync_at    DATETIME NULL,
  payment_status  ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  assessment_no   VARCHAR(30) NULL,
  total_amount    DECIMAL(10,2) NULL,
  amount_paid     DECIMAL(10,2) NULL,
  or_number       VARCHAR(30) NULL,
  paid_at         DATETIME NULL,
  submitted_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_student_term (student_id, term_id),          -- one enrollment per term
  CONSTRAINT fk_enr_student FOREIGN KEY (student_id) REFERENCES students(user_id),
  CONSTRAINT fk_enr_term    FOREIGN KEY (term_id)    REFERENCES terms(id)
) ENGINE=InnoDB;

-- Subjects in an enrollment. The unique keys block a duplicate section
-- and two sections of the same course (Requirement 1).
CREATE TABLE enrollment_subjects (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  enrollment_id  INT UNSIGNED NOT NULL,
  section_id     INT UNSIGNED NOT NULL,
  course_id      INT UNSIGNED NOT NULL,
  added_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_enr_section (enrollment_id, section_id),
  UNIQUE KEY uq_enr_course  (enrollment_id, course_id),
  CONSTRAINT fk_es_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
  CONSTRAINT fk_es_section    FOREIGN KEY (section_id)    REFERENCES sections(id),
  CONSTRAINT fk_es_course     FOREIGN KEY (course_id)     REFERENCES courses(id)
) ENGINE=InnoDB;

-- Itemized fee assessment returned by the Cashiering System (copy kept
-- in the portal so both systems show the same items and total).
CREATE TABLE assessment_items (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  enrollment_id  INT UNSIGNED NOT NULL,
  label          VARCHAR(100) NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  sort_order     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_ai_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Audit log
-- ---------------------------------------------------------------------
CREATE TABLE audit_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  role        VARCHAR(20) NOT NULL,
  action      VARCHAR(60) NOT NULL,
  details     VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB;

-- =====================================================================
-- SAMPLE DATA  (sample data only — matches the Figma screens)
-- =====================================================================

INSERT INTO terms (id, name, is_current) VALUES (1, '1st Sem 2026-2027', 1);

INSERT INTO settings (setting_key, setting_value) VALUES
  ('max_units', '21');

-- Test accounts
--   student@ecd.edu.ph    / student123    (Lee Santos, 2022-10100, BSIT)
--   juan@ecd.edu.ph       / student123    (Juan Dela Cruz, 2022-10101, BSIT)
--   registrar@ecd.edu.ph  / registrar123
--   cashier@ecd.edu.ph    / cashier123
INSERT INTO users (id, email, password_hash, first_name, last_name, role) VALUES
  (1, 'student@ecd.edu.ph',   '$2y$10$YB9/dtBvZVTCEJHdPvPxAuSkMYr/g6Gsgs0JJWhvcJIH.UKblh/kC', 'Lee',   'Santos',    'student'),
  (2, 'juan@ecd.edu.ph',      '$2y$10$YB9/dtBvZVTCEJHdPvPxAuSkMYr/g6Gsgs0JJWhvcJIH.UKblh/kC', 'Juan',  'Dela Cruz', 'student'),
  (3, 'registrar@ecd.edu.ph', '$2y$10$8w5e5mFzsSQ0ihMzm2KGC.jGU0sMBcqgty7wsy/RdZuIpL8LdzxDm', 'Maria', 'Santos',    'registrar'),
  (4, 'cashier@ecd.edu.ph',   '$2y$10$6QUAc3BsJx3jYazT57wBCeB2IvJ7iJB8szARbLCPhMKrT6UsD2IUi', 'Bea',   'Ramos',     'cashier');

INSERT INTO students (user_id, student_no, program, year_level) VALUES
  (1, '2022-10100', 'BSIT', 3),
  (2, '2022-10101', 'BSIT', 3);

INSERT INTO courses (id, code, title, units, has_lab) VALUES
  (1,  'ITEC 101', 'Human Computer Interaction 2',       3, 1),
  (2,  'ITEC 100', 'Information Assurance and Security', 3, 0),
  (3,  'ITEC 106', 'Web System Technologies II',         3, 1),
  (4,  'ITEC 105', 'Software Engineering',               3, 0),
  (5,  'ITEC 102', 'Systems Integration',                3, 0),
  (6,  'ITEC 107', 'Mobile Application Dev',             3, 1),
  (7,  'ITEC 108', 'Data Analytics',                     3, 1),
  (8,  'GEED 20',  'Ethics',                             3, 0),
  (9,  'NSTP 2',   'Civic Welfare Training',             3, 0),
  (10, 'ITEC 111', 'Advanced Databases',                 3, 1);

INSERT INTO sections (id, course_id, term_id, section_code, schedule, capacity, enrolled) VALUES
  (1,  1,  1, 'BSIT 3-1', 'MWF 8:00–9:00',   40, 12),
  (2,  2,  1, 'BSIT 3-1', 'TTh 9:00–10:30',  40, 30),
  (3,  3,  1, 'BSIT 3-1', 'MWF 10:00–11:00', 40, 38),
  (4,  4,  1, 'BSIT 3-1', 'TTh 1:00–2:30',   35, 20),
  (5,  5,  1, 'BSIT 3-1', 'MWF 1:00–2:00',   40, 15),
  (6,  6,  1, 'BSIT 3-1', 'TTh 3:00–4:30',   30,  8),
  (7,  7,  1, 'BSIT 3-1', 'Sat 8:00–11:00',  40, 40),
  (8,  8,  1, 'BSIT 3-1', 'MW 2:30–4:00',    45, 22),
  (9,  9,  1, 'BSIT 3-1', 'Sun 8:00–11:00',  60, 18),
  (10, 10, 1, 'BSIT 3-1', 'TTh 8:00–9:30',   35,  3);
