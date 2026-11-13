# Verified ZenHR attendance API examples — 2026-11-13

The user successfully downloaded the **public ZenHR Postman collection** from:
`https://artifacts.apis.io/zenhr-inc/openapi/zenhr-inc-postman-collection.json`.
These are request **examples**, not proof that an omitted field is accepted.

## Confirmed by the collection

ZenHR API base in its example: `https://aiapi.zenhr.com/api/v3`.
The current Superfix OAuth client uses `https://app.zenhr.com/api/v3`
and has successfully GET-requested `/who_am_i`, `/branches`,
`/branches/17787/employees`, and
`/branches/17787/attendance_records` **on that configured host**.
Do not change the host blindly.

**Bulk POST:** `/branches/{branch_id}/attendance_details/bulk_insert`

Example (anonymized):
```json
{
  "attendance_details": [
    {
      "request_time": "2026-11-13 08:00:00 +0400",
      "request_type": 0,
      "employee_id": 390630,
      "added_by": 2
    }
  ]
}
```
- `request_time`: exact device local time **plus `+0400` offset**.
  ZenHR documents that missing timezone defaults to UTC.
- `employee_id`: **internal ZenHR employee ID**, not the ZKTeco badge
  or the `employment_number`. Match via authenticated ZenHR GET
  without storing a manual employee map; fail closed on nonunique IDs.
- `request_type`: **0 = In; 1 = Out** in ZenHR API.
- `attendance_transaction_id`: optional for bulk; defaults to
  clocking function when omitted according to the example.
- `added_by: 2`: optional machine origin.
- Max bulk size from sample: 5000, but no bulk posting is enabled
  until individual integration/duplicate semantics are verified.

**Individual POST** example: `employee_id`, `attendance_detail`
(`request_time`, `request_type`, optional transaction, source etc.).
Another older sample uses a top-level flat
`request_time` + `request_type` pair. Prefer the latest schema once
confirmed directly with vendor.

## UNRESOLVED BLOCKER

The HR manager reports ZenHR handles punches in chronological
alternating In/Out order regardless of physical device and ZKTeco
In/Out labels. **The documented public POST examples still contain
explicit `request_type`.** This does NOT establish that the API
would automatically interpret In/Out from raw unordered punches.

Never send guessed In/Out, never derive it from the device label,
and never assign In/Out by blindly alternating locally. Existing
events, overnight shifts and corrections could make that inaccurate.

Ask ZenHR support/HR for the raw biometric SQL import workflow
currently in use. The vendor offers a ZKTeco/BioTime native integration
and advertises a hosted SQL Server attendance option. The current
Superfix topology can supply one canonical attendance feed with
`badge_number`, local punch time, and original device information
without changing AT1 HTTP or AT2 HTTPS.

## Current safety state

- One tenant branch: Superfix Group `17787`, company `11945`.
- User's preflight: 35 local unique punches, 22 pending, 13 held
  (Hili device clock). No local duplicates.
- ZenHR read-only scan: 561 daily records in Nov 11–14;
  19 pending local events share employee+date with ZenHR records,
  three not seen on those days. No exact remote duplicates proven.
- New read-only command `php artisan adms:zenhr-employee-match`
  finds candidate matching **internal** ZenHR employee IDs via
  `employment_number` and attendance record `card_id`.
  This command never sends attendance, persists mappings or
  prints names/tokens.
- **Never** enable an unattended POST until the raw event/direction
  contract is confirmed, existing events reconciled, and a
  one-event test has been verified in ZenHR.

