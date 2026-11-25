# API Reference

Two parts:

1. **Registration Portal ⇄ Cashiering System contract**. Ramirez's Cashiering System must follow this. The mock in `mock-cashiering/` already does, so use it as a working example.
2. **Registration Portal API**, used by the student and registrar pages.

All bodies are JSON. Every reply has this shape:

```json
{ "ok": true,  "data": { } }
{ "ok": false, "error": { "code": "UNITS_LIMIT", "message": "Maximum of 21 units" } }
```

---

## 1. Registration Portal ⇄ Cashiering System

Both systems send the header `X-API-Key: <shared key>`. The key is set in `config.php` under `cashiering.api_key`, and the Cashiering System must use the same value.

### 1a. Portal → Cashiering: send an enrollment

`POST {cashiering base_url}/assessments.php`

The portal calls this once when the student submits. It calls it again on **Retry**, so the same `enrollment_ref` **must return the same assessment** instead of creating a second one.

Request:
```json
{
  "enrollment_ref": "EN-2026-000001",
  "term": "1st Sem 2026-2027",
  "submitted_at": "2026-11-25 10:00:00",
  "student": {
    "student_no": "2022-10100", "last_name": "Santos", "first_name": "Lee",
    "email": "student@ecd.edu.ph", "program": "BSIT", "year_level": 3
  },
  "total_units": 15,
  "subjects": [
    { "code": "ITEC 101", "title": "Human Computer Interaction 2", "units": 3,
      "has_lab": true, "section": "BSIT 3-1", "schedule": "MWF 8:00–9:00" }
  ]
}
```

Reply (`200` or `201`):
```json
{
  "ok": true,
  "data": {
    "assessment": {
      "assessment_no": "AS-7001",
      "enrollment_ref": "EN-2026-000001",
      "items": [
        { "label": "Tuition (₱150 × 15 units)", "amount": 2250.00 },
        { "label": "Laboratory fee",             "amount": 1200.00 },
        { "label": "Miscellaneous fees",         "amount": 1850.00 }
      ],
      "total": 5300.00,
      "status": "unpaid",
      "amount_paid": null,
      "or_number": null,
      "paid_at": null
    }
  }
}
```

Rules the portal checks:
- `assessment_no`, `items` (at least one) and `total` are required.
- `total` must equal the sum of `items[].amount`. This is how both systems show the same total (Requirement 2).
- The portal treats any of these as "Cashiering is down" and keeps the enrollment as **Pending + Retry** (Requirement 4): no answer within `cashiering.timeout` seconds, a `5xx` status, or `"ok": false`.

### 1b. Portal → Cashiering: get payment status

`GET {cashiering base_url}/assessments.php?enrollment_ref=EN-2026-000001`

The reply uses the same `assessment` object. When the cashier has recorded the payment, it looks like this:
```json
{ "status": "paid", "or_number": "OR-2026-004217", "amount_paid": 5300.00, "paid_at": "2026-11-25 10:15:00" }
```
The portal polls this every 5 seconds while the student is on the Payment status screen.

### 1c. Cashiering → Portal: payment recorded (callback)

`POST {portal}/api/cashiering-callback.php`

Call this right after the cashier records a payment, so the student's page updates immediately. Polling (1b) also works if this call fails.

```json
{
  "enrollment_ref": "EN-2026-000001",
  "assessment_no": "AS-7001",
  "status": "paid",
  "or_number": "OR-2026-004217",
  "amount_paid": 5300.00,
  "paid_at": "2026-11-25 10:15:00"
}
```
The portal rejects the call when:
- the API key is wrong (`401`)
- the `enrollment_ref` is unknown (`404`)
- `assessment_no` doesn't match the enrollment (`409`)
- `or_number` is missing (`422`)
- `amount_paid` is zero or negative (`422`). This is part of Requirement 3. The Cashiering System should also block these amounts on its own cashier screen.

---

## 2. Registration Portal API (`/api`)

The portal uses a session cookie for login. A request without a login gets `401`. A request from the wrong role gets `403` (Requirement 5).

### Auth
| Method | Endpoint | Body | Notes |
|---|---|---|---|
| POST | `login.php` | `{email, password}` | Returns `{user, redirect}`. Wrong login → `401 INVALID_CREDENTIALS` |
| POST/GET | `logout.php` | | |
| GET | `me.php` | | Logged-in user and current term |

### Student
| Method | Endpoint | Body | Notes |
|---|---|---|---|
| GET | `sections.php?q=` | | Sections this term: slots, `available` |
| GET | `selection.php` | | Current enrollment |
| POST | `selection.php` | `{section_id}` | Add. Errors: `UNITS_LIMIT`, `DUPLICATE_SECTION`, `DUPLICATE_COURSE`, `SECTION_FULL`, `ENROLLMENT_LOCKED` |
| DELETE | `selection.php?section_id=` | | Remove |
| POST | `submit.php` | | Saves the enrollment, then sends it to Cashiering. `stage` = `assessed` or `pending_sync` |
| GET | `enrollment.php[?refresh=1]` | | `refresh=1` also asks Cashiering for the payment status |
| POST | `retry.php` | | Re-sends a Pending enrollment, or re-checks the payment status |
| POST | `demo.php` | `{action: pay\|offline\|online}` | Only when `demo_mode` is on. Talks to the mock |

Enrollment object (short version):
```json
{
  "id": 1, "reference_no": "EN-2026-000001", "term": "1st Sem 2026-2027",
  "status": "submitted",
  "stage": "not_started | selecting | pending_sync | assessed | paid",
  "total_units": 15, "max_units": 21,
  "subjects": [{ "section_id": 1, "code": "ITEC 101", "title": "...", "units": 3, "schedule": "...", "section_code": "BSIT 3-1" }],
  "sync": { "status": "not_sent | synced | failed", "attempts": 1, "error": null },
  "payment": { "status": "pending | paid", "assessment_no": "AS-7001", "items": [], "total": 5300, "or_number": null }
}
```

### Registrar
| Method | Endpoint | Body | Notes |
|---|---|---|---|
| GET | `registrar/enrollments.php?filter=all\|paid\|pending\|failed&q=` | | List and counts |
| GET | `registrar/enrollments.php?id=` | | One enrollment with subjects and fees |
| POST | `registrar/enrollments.php` | `{id}` | Retry sending to Cashiering |
| GET / PATCH / POST | `registrar/sections.php` | PATCH `{id, capacity?, schedule?, is_open?}` · POST `{course_id, section_code, schedule, capacity}` | Capacity can't go below the number of students already enrolled |
| GET / POST | `registrar/courses.php` | POST `{code, title, units, has_lab}` | |
