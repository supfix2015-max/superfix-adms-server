# Attendance source of truth — approved two-database cutover

Status: **PREPARATION ONLY — NOT CLEARED FOR PRODUCTION CUTOVER** (2026-11-15).

## Contract

- `Att2000_V4.dbo.Attendance` is the **only authoritative attendance ledger**. It must be usable after the Laravel/Coolify/ADMS application is lost.
- `AttendanceCloudV2.adms.attendance_logs` is an ingestion/operational/audit projection; not an alternative authoritative ledger.
- Every valid new attendance punch must eventually be present in `Att2000_V4.dbo.Attendance` and must not be reported as *committed* before the authoritative INSERT succeeds.
- The ZKTeco intake identity is verified against `Att2000_V4.dbo.Device`. Do not include office test terminal `BZD5261200843`.
- A dedicated device/ingestion SQL principal has **SELECT + INSERT only** for original `dbo.Attendance`; no UPDATE, DELETE, ALTER or schema permissions.
- Only the future authenticated administrative editor may change existing authoritative rows, through a distinct account, with before/after audit and origin attribution.
- Changes/cancellations in `dbo.Attendance` project to ADMS **one way**; changing ADMS cannot update/delete original records. Cancellations remain for forensic review, while excluded from trusted reports and ZenHR. Preserve deletion history in authoritative recovery backups or an authoritative audit ledger.
- ZenHR reads **only `dbo.Attendance`** via a dedicated outbox. No staged-only attendance can be POSTed. The date safeguard is **2026-11-15 00:00:00 UAE local**. The original BioWeb exporter must be confirmed stopped for this interval before enabling POST.

## Current finding: cutover is NOT yet complete

The current intake writes unapproved devices to operational staging only. In a recent live sample the Ain Al Fayidah terminal `QQD4241200612` uploaded 207 records to ADMS via `DATA QUERY ATTLOG`. Transactions shows original `dbo.Attendance` only. Existing ZenHR outbox reads **both** staging and original. No production toggle should be changed until reconciled.

The current `LegacyAttendanceWriter` uses an exact-match test on badge, serial, local time, and status to avoid duplicate original rows. **Latest live discovery (2026-11-15):** both Laravel connections report `DB_NAME() = Att2000_V4`. This is NOT proof of separate physical databases, and even matching names alone do not establish whether both connections use the same SQL Server instance. Confirm host/instance with `SERVERPROPERTY('ServerName')`, `@@SERVERNAME` and `DB_NAME()` before designing a cross-database migration. The existing `config/database.php` comment also describes ADMS in the original database, using a distinct `adms` schema. The two-DB separation is an **approved target state**, not an established current state.

The original and ADMS staging currently have 868 vs 353 records since Nov 3 across four branch devices (counts include Hili 293/293, Ain Al Fayidah 207/22, Um Ghafah 182/18, Al Maqam 186/20). **515 is only the difference between counts, NOT a verified list of 515 safe inserts**. The original currently contains zero Nov-15+ punches for the last three branches. Validate badge, serial, device-local time and status before promotion. Until cutover, reporting from `dbo.Attendance` is incomplete.

For the eventual two-database design, commits across the two databases must not be assumed atomic unless a specifically validated mechanism is used. Use a **durable, idempotent, retryable state machine**, never assume both records committed together.

## Stage 0 — immutable evidence and recovery prerequisites

1. **Freeze ZenHR outbound:** `ZENHR_ATTENDANCE_AUTO_SEND=false`, `ZENHR_ATTENDANCE_POST_ENABLED=false` on the console. Ensure the old BioWeb service does not export overlapping dates when switching later.
2. Record deployed container Git ref, `DB_CONNECTION`, actual SQL Server instance identities (`SERVERPROPERTY('ServerName')` for each connection), current `DB_NAME()`, list of actual databases and `adms` table counts, device timezone, writer allowlist and application role. Do not print credentials. DO NOT change `ADMS_SQLSRV_DATABASE` or switch to `AttendanceCloudV2` until operational tables are migrated, validated and the cutover plan is rehearsed.
3. Take a full, verified SQL Server `.bak` backup of **every database currently present**. If both schemas are currently in the same `Att2000_V4` database, back it up as one original database; when `AttendanceCloudV2` actually exists as a separate operational database, back it up separately. Keep encrypted copies **off the VPS**; record backup timestamp and recovery model. Verify with `RESTORE VERIFYONLY` and a **real restore test to an isolated instance**. `VERIFYONLY` alone cannot guarantee recoverability.
4. Before retention/deletion work, identify SQL Server recovery mode, log backup cadence, SQL Server edition, backup encryption, available disk space, RPO/RTO. Backups on the same VPS alone do not survive loss of the VPS.
5. Execute the read-only Laravel audit (below) and reconcile original times/badges against each device. Pay special attention to Hili pre-2026-11-12 time-offset history.

### Read-only preflight (after deploying *only* audited command on a nonproduction clone)

```bash
php artisan adms:attendance-source-audit \
  --serial=CEG9223661162 \
  --serial=QQD4241200612 \
  --serial=A555180560772 \
  --serial=QQD4235000441 \
  --sample=50
```

The command **cannot** import, update, delete, issue ZKTeco commands or POST ZenHR. Sample "absent" is approximate by badge+serial+time and is **not** an insert-ready list. Device timezone and status mapping require separate validation.

## Stage 1 — ingestion reliability (not yet implemented)

Before physical DB separation, compare and reconcile the current **same-named database connections**; stabilize authoritative insertion from every registered branch. Later, make a snapshot of **all operational ADMS tables, foreign keys, queues, outbox state and schema grants** and migrate them to the physically separate operational DB. Switching a connection name alone can strand the application without tables or data.

1. Keep normal ZKTeco `/iclock/cdata` ingestion ACK semantics only after a durable event is recorded; stage pending events with a stable per-device payload/event key.
2. Independently promote validated rows into the authoritative original table using the restricted INSERT account, in short batches. Match badge to `dbo.Employee`, device to `dbo.Device`, normalize UAE wall-clock, and preserve status/verification method.
3. Deduplicate against existing original rows under the SQL Server transactional app lock before each INSERT. Do not auto-insert ambiguous, old clock-shifted or unknown-badge punches.
4. Record original `dbo.Attendance.Id` as a correlation key in ADMS with promotion state (`pending`, `committed`, `needs_review`, `error`) and retry metadata. Do not present `pending` as officially committed.
5. If original INSERT commits but ADMS acknowledgement/correlation fails, a retry must discover the same original event and mark it committed instead of inserting again.
6. Design the device ACK/automatic retry path to prevent a permanent, acknowledged *ADMS-only* row on failures. Count and alert on any ADMS rows not confirmed in original.
7. For destructive changes, the future administrator operates **only via authenticated original-db mutation service**; store reversible before/after audit, and propagate correction/deletion status one way to the operational projection. Original deletion must not be resurrected by ingestion retry (use durable tombstones).

## Stage 2 — reconcile historical devices (not yet implemented)

- Scope: legacy-registered branch devices, starting **2026-11-03**. Skip the office terminal.
- Compare original and operational rows by employee badge, serial, **local** time, status, and verification method.
- Dry-run first; report counts: already in original, missing, invalid badge, clock ambiguity, duplicate, in review.
- Never bulk INSERT unsupervised historical rows: old exporter may have already imported them and Hili earlier clocks require specific correction.
- Apply only approved batches; verify the original Transactions view reflects exactly those rows.

## Stage 3 — dbo-only ZenHR (not yet implemented)

- Collect *only* `Att2000_V4.dbo.Attendance` into the outbox; ignore stage-only events and quarantine older pending ADMS-derived outbox entries.
- Outbound worker must recheck source `dbo.Attendance.Id`, badge, timestamp, status, and cancellation state before sending, not just trust an old outbox snapshot.
- At exactly `2026-11-15 00:00:00` in `Asia/Dubai`, evaluate **punch time**, not upload time. No pre-cutover POST.
- De-duplicate against old BioWeb ZenHR exports and review ZenHR results (HTTP 202 means accepted, not necessarily displayed/imported).
- Turn on auto POST only after original ingestion completeness and old-exporter stop are independently confirmed.

## Stage 4 — amendments, cancellations, monitoring and disaster recovery

- Editor changes official rows in original only. Propagate to ADMS one-way by a reliable change log/outbox or SQL Change Tracking/CDC once schema, privileges and retention are validated.
- Soft-delete means *withdraw approval and hide* in ADMS, with retrievable audit event; design an explicit original-side cancellation/tombstone ledger if old `dbo.Attendance` cannot represent cancelled rows without breaking compatibility.
- Alert on unpromoted events, stale device polls, projector lag, bad badges, DB write failures, outbox duplicates and lack of verified offsite backups.
- Rehearse losing the ADMS application: restore `Att2000_V4` into an isolated SQL Server and query employees/devices/attendance without Laravel. Measure gaps versus verified backup recovery point.

## Do not do

- Do not set `LEGACY_SQLSRV_ATTENDANCE_WRITE_ALL_REGISTERED=true` or enable ZenHR sending before the above gates.
- Do not update/delete original `dbo.Attendance` using the ADMS writer.
- Do not bulk-run ad-hoc SQL INSERTs, clear the attendance tables or reset outbox cursors in production.
- Do not treat an ADMS-only staging event as a successfully committed original record.
