# Registration Portal: back-end (ITEC 116 Final Project)

University Online Student Registration Portal with an Integrated Cashiering System.
**This folder is the Registration Portal**: the database, the back-end for enrollment, courses and sections, and the student and registrar pages based on the Figma UI.
Stack: PHP 8 + MySQL (XAMPP), HTML/CSS/JavaScript, JSON REST API.

## Setup (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\registration-portal`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open phpMyAdmin (http://localhost/phpmyadmin), go to **Import**, and choose `database/registration_portal.sql`.
   This creates the `registration_portal` database with sample data. Import it again any time to reset everything.
4. Open http://localhost/registration-portal/

If your MySQL has a password or uses a different port, change `db` in `config.php`.

### Test accounts
| Role | Email | Password |
|---|---|---|
| Student | student@ecd.edu.ph | student123 |
| Student | juan@ecd.edu.ph | student123 |
| Registrar | registrar@ecd.edu.ph | registrar123 |
| Cashier | cashier@ecd.edu.ph | cashier123 (goes to the Cashiering System) |

## Folder structure
```
config.php                 settings (database, Cashiering URL and API key, demo mode)
database/registration_portal.sql   tables + sample data
lib/                       back-end logic
  enrollment.php           enrollment rules: 21-unit limit, duplicates, slots, submit
  cashiering.php           connection to the Cashiering System (send, poll, retry)
  auth.php                 login and role checks
api/                       JSON endpoints (see API.md)
index.php                  login page (all roles)
student/                   dashboard, 4-step enrollment, printable summary
registrar/                 enrollments, sections, courses
mock-cashiering/           MOCK Cashiering System for testing only
```

## Connecting the real Cashiering System
`API.md` has the contract. While the real system isn't ready, the portal uses `mock-cashiering/`, which follows the same contract.
To switch over, change `cashiering.base_url` (and `api_key`) in `config.php` to the real system's URL, for example `http://localhost/cashiering/api`.
The real system needs to:
- have `POST assessments.php` and `GET assessments.php?enrollment_ref=…`
- call `api/cashiering-callback.php` after recording a payment

## How to check each requirement
| # | Requirement | Steps |
|---|---|---|
| 1 | Block more than 21 units and duplicate sections | Log in as student → Start enrollment. Add 7 subjects (21 units), then click **Add** on another one: **"Maximum of 21 units"** appears. Click **Added** on a subject you already added: **"This section is already added."** appears. In phpMyAdmin, `enrollment_subjects` has no extra row. |
| 2 | Submit sends the enrollment to Cashiering and returns an itemized assessment | Continue to review → Submit enrollment → Submit. Fee assessment shows tuition, laboratory and miscellaneous fees. The same items and total appear in the mock cashier page (http://localhost/registration-portal/mock-cashiering/) and in the registrar page. |
| 3 | Cashier records payment: Pending becomes Paid with an OR number | Open the mock cashier page and click **Record**. The student's Payment status page changes to **You're enrolled!** with the OR number within about 5 seconds. Entering `0` or `-100` as the amount is rejected. |
| 4 | Cashiering down: the enrollment is still saved and shows Pending + Retry | On the mock cashier page, click **Simulate outage**. Then submit an enrollment as `juan@ecd.edu.ph`. You see **"Your enrollment is saved…"** with a Pending badge and **Retry**, and the record is in `enrollments` with `sync_status = failed`. Click **Bring back online**, then **Retry**: the fee assessment appears. |
| 5 | Each role sees only its own screens | Each account redirects to its own page. While logged in as a student, open `/registration-portal/registrar/index.php`: **Access denied (403)**. The API also answers `403` for another role's endpoints. |

**Demo mode** (`demo_mode` in `config.php`) shows the Figma buttons "Demo: cashier recorded payment" and "Demo: Cashiering unavailable" on the Payment status screen. Set it to `false` for the final version.

## Notes
- Passwords are hashed with `password_hash` (bcrypt). All queries use prepared statements.
- Submitting is a database transaction: it takes the section slots and locks the enrollment **before** contacting Cashiering, so nothing is lost if Cashiering is down.
- The maximum units come from the `settings` table (`max_units`), not a hard-coded 21.
- The fee amounts (₱150 per unit, ₱1,200 lab fee, ₱1,850 miscellaneous) live in the **mock** only, because fees belong to the Cashiering System.
