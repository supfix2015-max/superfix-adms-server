# ADMS Server Project Documentation

## Purpose

This file is the consolidated technical documentation for the current codebase in `/home/ayaan/Downloads/ADMS-server-ZKTeco-main`.

It explains:

- what the project does
- how the application is structured
- which files/classes own each subsystem
- how device ingest, attendance, employee sync, commands, and biometric replay work
- how to run, test, and operate the project

This document is meant to be the "single overview" for the repository. The more specialized documents in the root such as `README.md`, `API.md`, `ADMS-PROTOCOL.md`, `DEPLOYMENT.md`, and `docs/ZKTECO_ADMS.md` still remain useful for deeper reference.

---

## 1. Project Summary

This project is a Laravel 12 application for managing ZKTeco biometric devices through the ADMS / iClock protocol.

At a high level, it provides:

- plain-text ADMS endpoints for ZKTeco devices
- JSON API endpoints for device or dashboard integrations
- an admin web panel for devices, employees, credentials, attendance, commands, and logs
- local storage of raw ADMS payloads for troubleshooting
- employee sync and command queueing for devices
- biometric enrollment, storage, replay, and template synchronization
- legacy report pages for older attendance/device workflows still present in the repository

The current UI has been modernized into a shared enterprise-style Blade interface built around:

- `resources/views/admin/layout.blade.php`
- `resources/views/layouts/app.blade.php`
- `public/enterprise-ui.css`

---

## 2. Tech Stack

### Backend

- PHP `^8.3`
- Laravel `^12.0`
- Laravel Breeze
- Laravel Sanctum
- Laravel UI

### Data / Reporting

- MySQL
- Yajra DataTables
- League CSV
- Maatwebsite Excel

### Frontend

- Blade templates
- Bootstrap
- Bootstrap Icons
- jQuery DataTables for some legacy report screens
- custom enterprise stylesheet in `public/enterprise-ui.css`

### Tooling

- PHPUnit / Pest-compatible test setup
- Vite config exists in `vite.config.js`
- Composer autoloads `app/Helpers/GitHelper.php`

---

## 3. Repository Structure

```text
app/
  Console/Commands/         Custom Artisan commands for sync, cleanup, and replay
  DataTransferObjects/      DTOs used by sync and attendance flows
  Events/                   Domain events, mainly biometric-template events
  Http/
    Controllers/
      Admin/                Main admin panel controllers
      Api/                  JSON API controllers
      Auth/                 Authentication and password flow
    Middleware/             Auth, admin alias, force password change, etc.
    Requests/               Request validation classes
  Jobs/                     Queue jobs for attendance, raw logs, and biometric sync
  Listeners/                Event listeners
  Models/                   Eloquent models for all major entities
  Notifications/            Failure and reenrollment notifications
  Providers/                Route/event/auth/app providers
  Repositories/             Query and persistence layer for main entities
  Services/                 Core business logic and protocol handling

config/
  zkteco.php                Main ADMS/ZKTeco runtime configuration

database/
  migrations/               Legacy and current schema migrations
  seeders/                  Basic sample data

public/
  enterprise-ui.css         Shared modern UI theme

resources/views/
  admin/                    Main enterprise admin panel
  auth/                     Login/register/password views
  devices/                  Legacy report pages
  layouts/                  Shared app layout

routes/
  web.php                   Admin UI, auth include, and legacy report routes
  api.php                   JSON API routes
  device.php                ADMS/iClock plain-text device endpoints
  auth.php                  Login/password/email verification routes

tests/
  Feature/                  Main application tests
  Unit/                     Unit test placeholder
```

---

## 4. Runtime Architecture

The application has four main runtime surfaces.

### 4.1 ADMS / iClock Device Endpoints

These are registered in `routes/device.php` and handled by `App\Http\Controllers\IclockController`.

Endpoints:

- `/iclock/cdata`
- `/iclock/getrequest`
- `/iclock/devicecmd`
- `/iclock/test`

The controller delegates almost all real work to `App\Services\ZKTecoService`.

### 4.2 JSON API

These routes live in `routes/api.php`.

They are used for:

- JSON-based device registration and heartbeat
- JSON log upload
- command fetch / dashboard summary
- health checks

Main controller:

- `App\Http\Controllers\Api\DeviceApiController`
- `App\Http\Controllers\Api\DashboardApiController`

### 4.3 Admin Web Panel

These routes live in `routes/web.php` under the `/admin` prefix.

Main sections:

- dashboard
- devices
- employees
- credentials
- attendance
- commands
- logs

Main controllers:

- `DashboardController`
- `DeviceController`
- `EmployeeController`
- `CredentialController`
- `AttendanceController`
- `CommandController`
- `LogController`
- `UserSyncController`

### 4.4 Legacy Reports / Utility Pages

Older report-style pages still exist under `resources/views/devices/*` and are served by the non-admin `App\Http\Controllers\DeviceController` and `App\Http\Controllers\EmployeeController`.

These are mainly:

- `/devices/attendance`
- `/devices/daily`
- `/devices/monthly`
- legacy mapping/log pages

This means the repository is a hybrid of:

- modern normalized ADMS/admin workflows
- older utility/report pages still kept for compatibility

---

## 5. Route Map

## 5.1 ADMS Device Routes

Defined in `routes/device.php`.

| Route | Methods | Purpose |
| --- | --- | --- |
| `/iclock/cdata` | GET, POST | handshake, metadata, attendance, user info, biometric callbacks |
| `/iclock/getrequest` | GET, POST | device polls pending commands |
| `/iclock/devicecmd` | GET, POST | device command callback / acknowledgment |
| `/iclock/test` | GET, POST | device heartbeat / test ping |

## 5.2 JSON API Routes

Defined in `routes/api.php`.

| Route | Method | Purpose |
| --- | --- | --- |
| `/api/device/register` | POST | device registration |
| `/api/device/ping` | POST | heartbeat |
| `/api/device/logs` | POST | JSON log upload |
| `/api/device/command` | POST | JSON command fetch |
| `/api/dashboard/summary` | GET | summary data |
| `/api/dashboard/devices` | GET | device list data |
| `/api/dashboard/logs` | GET | dashboard log feed |
| `/api/health` | GET | health check |

## 5.3 Admin Routes

Defined in `routes/web.php`.

Main route groups:

- `/admin/dashboard`
- `/admin/devices/*`
- `/admin/employees/*`
- `/admin/credentials/*`
- `/admin/attendance`
- `/admin/attendance/export`
- `/admin/commands/*`
- `/admin/logs`
- `/admin/logs/{id}/raw`
- `/admin/user-syncs/*`

Extra employee actions:

- biometric enrollment queueing
- biometric enrollment status polling
- push stored biometrics

## 5.4 Auth Routes

Defined in `routes/auth.php`.

Includes:

- login/logout
- register
- forgot password / reset password
- confirm password
- email verification

---

## 6. Core Class Ownership

## 6.1 Controllers

### Device-side protocol

- `app/Http/Controllers/IclockController.php`
  - thin controller for plain-text ADMS endpoints
  - all endpoint methods call `ZKTecoService`

### Admin controllers

- `Admin/DashboardController.php`
  - dashboard stats and recent records
- `Admin/DeviceController.php`
  - CRUD, queue device command, queue pull, refresh token, device detail page
- `Admin/EmployeeController.php`
  - CRUD, biometric enrollment, push stored biometrics, employee details
- `Admin/CredentialController.php`
  - card/passcode/common code management and biometric enrollment from credentials page
- `Admin/AttendanceController.php`
  - filtered attendance listing and CSV export
- `Admin/CommandController.php`
  - command queue UI
- `Admin/LogController.php`
  - raw logs, error logs, recent device commands
- `Admin/UserSyncController.php`
  - AJAX queueing of global employee/biometric sync batches

### Legacy / non-admin controllers

- `app/Http/Controllers/DeviceController.php`
  - older report views and DataTables JSON endpoints
- `app/Http/Controllers/EmployeeController.php`
  - older employee ID-to-name mapping UI
- `app/Http/Controllers/UserController.php`
  - operator account management and change-password view
- `app/Http/Controllers/HomeController.php`
  - post-login home page

## 6.2 Services

### ADMS and protocol handling

- `ZKTecoService`
  - primary ADMS orchestration service
  - resolves device, stores raw log, parses payload, updates device state, queues/dispatches commands
- `ZKTecoPayloadParser`
  - parses metadata, attendance payloads, user payloads, and biometric payloads
- `ZKTecoCommandFormatter`
  - converts `DeviceCommand` records into outbound device command strings
- `ADMSPayloadService`
  - older generic ADMS payload parser service; still present in repo
- `SystemClockService`
  - timezone normalization and device time formatting

### Device / employee / attendance domain

- `DeviceService`
  - register/update device, heartbeat handling, queue command/time sync
- `EmployeeService`
  - employee CRUD and employee deletion/cleanup logic
- `AttendanceService`
  - attendance repository-facing domain service
- `CommandService`
  - queue commands, deduplicate reusable commands, record replies, manage retry behavior

### Sync and biometrics

- `UserSyncService`
  - ingest device-side users, sanitize sync-safe dataset, queue per-device sync batches
- `BiometricEnrollmentProgressService`
  - computes enrollment progress/status for UI polling
- `BiometricSyncService`
  - queues ADMS replay of stored biometrics to other devices
- `BiometricTemplateSyncService`
  - template synchronization logic, optional UDP-assisted compatibility path
- `ZKTecoBiometricSyncService`
  - thin extension wrapper around `BiometricTemplateSyncService`

## 6.3 Repositories

Repository classes exist for the main normalized entities:

- `AttendanceLogRepository`
- `DeviceCommandRepository`
- `DeviceRepository`
- `DeviceSyncRepository`
- `EmployeeRepository`
- `BaseRepository`

These are used mainly by services and some admin controllers for filtered pagination/statistics.

## 6.4 Models

Main models:

- `Device`
- `Employee`
- `AttendanceLog`
- `RawLog`
- `DeviceCommand`
- `ErrorLog`
- `EmployeeBiometric`
- `BiometricTemplate`
- `DeviceSync`
- `User`

Legacy-support models still present:

- `Attendance`
- `FingerLog`

## 6.5 Jobs, Events, Listeners

### Jobs

- `ProcessAttendanceLogJob`
- `StoreRawLogJob`
- `SendDeviceCommandJob`
- `PrepareUserSyncBatchJob`
- `SyncBiometricTemplate`
- `SyncBiometricUserJob`

### Event-driven biometric sync

- Event: `BiometricTemplateStored`
- Listener: `QueueBiometricTemplateSync`

When a biometric template is stored, the listener can queue cross-device biometric synchronization work.

## 6.6 Console Commands

Custom Artisan commands in `app/Console/Commands`:

- `adms:cleanup-users`
- `adms:queue-user-sync`
- `biometric:sync`
- `adms:reprocess-biometric-callbacks`
- `adms:sync-biometric-templates`
- `biometric:sync-employee-templates`

These support:

- cleaning malformed legacy user rows
- queueing safe full-user sync
- template synchronization between devices
- replaying missed biometric callbacks
- employee-specific biometric sync operations

---

## 7. Database Model Overview

The schema mixes legacy tables and newer normalized ADMS tables.

### Core operational tables

| Table / Entity | Purpose |
| --- | --- |
| `devices` | registered device records, token, network info, heartbeat, status |
| `employees` | employee master data, credentials, sync metadata |
| `attendance_logs` | normalized attendance rows from ADMS payloads |
| `raw_logs` | raw inbound requests and processing metadata |
| `device_commands` | pending/sent/done/failed command queue |
| `error_logs` | ingest and processing errors |
| `employee_biometrics` | locally stored biometric payload copies for replay |
| `biometric_templates` | template-centric biometric storage with encrypted `template_data` |
| `users` | operator/admin accounts |
| `device_syncs` | sync-batch tracking model used by queueing flows |

### Legacy-support tables

| Table / Entity | Purpose |
| --- | --- |
| `attendances` | older attendance table for legacy report pages |
| `finger_log` | older finger log table |
| `device_log` | older raw device log table |

### Important relationship examples

- a `Device` has many `attendanceLogs`, `rawLogs`, `commands`, `errorLogs`, and `employeeBiometrics`
- an `Employee` has many `attendanceLogs` and `biometrics`
- a `DeviceCommand` belongs to a device and optionally an employee or creator
- a `BiometricTemplate` belongs to an employee and source device

---

## 8. Key Workflows

## 8.1 Device Registration and Handshake

1. A device calls `/iclock/cdata`.
2. `IclockController` forwards to `ZKTecoService::handleCdata()`.
3. A `RawLog` record is created immediately.
4. The service resolves or auto-registers the device.
5. Device heartbeat / metadata fields are updated.
6. If it is an options handshake, the app returns configuration text to the device.

Main files:

- `routes/device.php`
- `app/Http/Controllers/IclockController.php`
- `app/Services/ZKTecoService.php`
- `app/Services/DeviceService.php`

## 8.2 Attendance Ingest

1. Device posts ATTLOG data to `/iclock/cdata`.
2. `ZKTecoPayloadParser` parses tab-delimited or metadata-style rows.
3. Parsed attendance rows are stored in `attendance_logs`.
4. Duplicate protection uses payload/device keys and parser logic.
5. Raw request data remains available in `raw_logs`.

Main files:

- `app/Services/ZKTecoPayloadParser.php`
- `app/Services/ZKTecoService.php`
- `app/Models/AttendanceLog.php`
- `app/Repositories/AttendanceLogRepository.php`

## 8.3 Device Polling and Command Dispatch

1. Device polls `/iclock/getrequest`.
2. `ZKTecoService` checks for pending/retryable `device_commands`.
3. `ZKTecoCommandFormatter` builds protocol command text.
4. Command is marked sent/dispatched.
5. Device later replies via `/iclock/devicecmd`.
6. `CommandService` records the response and updates command status.

Main files:

- `app/Services/CommandService.php`
- `app/Services/ZKTecoCommandFormatter.php`
- `app/Models/DeviceCommand.php`

## 8.4 Employee Sync

1. Admin queues sync from employee page or console command.
2. `UserSyncService` builds a sync-safe dataset.
3. `DeviceSync` batches and `DeviceCommand` rows are created.
4. Devices poll and receive queued user sync commands.
5. Device-side user uploads can also be ingested back into `employees`.

Main files:

- `app/Services/UserSyncService.php`
- `app/Console/Commands/AdmsQueueUserSyncCommand.php`
- `app/Jobs/PrepareUserSyncBatchJob.php`

## 8.5 Online Biometric Enrollment

1. Admin starts enrollment from employee or credential page.
2. The app queues an enrollment command to a target online device.
3. The device prompts for fingerprint or face enrollment.
4. The device uploads the template back via ADMS callback.
5. The server stores template data in biometric storage tables.
6. Enrollment progress can be polled for UI feedback.

Main files:

- `app/Http/Controllers/Admin/EmployeeController.php`
- `app/Http/Controllers/Admin/CredentialController.php`
- `app/Services/BiometricEnrollmentProgressService.php`
- `app/Services/ZKTecoService.php`

## 8.6 Push Stored Biometrics / Replay

1. The employee must already have stored fingerprint/face templates in the database.
2. Admin chooses target device(s) and template scope.
3. `BiometricSyncService` loads local biometric copies.
4. ADMS replay commands are queued for target devices.
5. Devices receive replay commands through normal polling.

Main files:

- `app/Services/BiometricSyncService.php`
- `app/Services/BiometricTemplateSyncService.php`
- `app/Jobs/SyncBiometricTemplate.php`

## 8.7 Employee Deletion

Current application behavior is designed to remove the employee from:

- the local database
- stored biometric/template tables
- pending command references
- connected devices via queued cleanup commands

Related config:

- `config/zkteco.php` -> `delete_employee_commands`

Main file:

- `app/Services/EmployeeService.php`

## 8.8 Logs and Diagnostics

The system keeps multiple audit surfaces:

- `raw_logs` for the exact inbound request body
- `error_logs` for processing problems
- `device_commands` for the control-plane audit trail

The admin logs page shows all three with independent pagination.

---

## 9. UI Overview

## 9.1 Main admin UI

Primary admin views live in:

- `resources/views/admin/*`

Main pages:

- dashboard
- devices
- employees
- credentials
- attendance
- commands
- logs

The admin shell is:

- `resources/views/admin/layout.blade.php`

The shared visual system is:

- `public/enterprise-ui.css`

## 9.2 Auth and general app layout

- `resources/views/layouts/app.blade.php`
- `resources/views/auth/*`
- `resources/views/home.blade.php`
- `resources/views/welcome.blade.php`
- `resources/views/users/index.blade.php`

## 9.3 Legacy report UI

Older report pages remain under:

- `resources/views/devices/*`

These still matter for:

- legacy attendance tables
- daily/monthly summaries
- old employee-name mapping workflow

---

## 10. Running the Project

## 10.1 Development Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

If needed for frontend asset work:

```bash
npm install
npm run dev
```

Note: the main enterprise admin theme currently comes from `public/enterprise-ui.css` and CDN-hosted assets, so the admin UI is not blocked on a Vite build.

## 10.2 Useful Runtime Commands

```bash
php artisan migrate
php artisan db:seed
php artisan optimize:clear
php artisan view:cache
php artisan route:list
php artisan queue:work
```

## 10.3 Custom ADMS Commands

```bash
php artisan adms:cleanup-users
php artisan adms:queue-user-sync
php artisan adms:reprocess-biometric-callbacks
php artisan adms:sync-biometric-templates
php artisan biometric:sync
php artisan biometric:sync-employee-templates
```

---

## 11. Testing

Tests are under:

- `tests/Feature`
- `tests/Unit`

Current test coverage includes:

- ADMS/iClock endpoint behavior
- dashboard/admin routes
- employee/device/admin pages
- biometric sync scenarios
- logs page behavior

Main test files include:

- `tests/Feature/ZktecoAdmsQaTest.php`
- `tests/Feature/ZktecoAdmsTest.php`
- `tests/Feature/AdminEmployeesPageTest.php`
- `tests/Feature/AdminLogsPageTest.php`
- `tests/Feature/DeviceShowTest.php`

Run tests with:

```bash
php artisan test
```

Testing configuration:

- `phpunit.xml` forces `APP_ENV=testing`
- `phpunit.xml` forces `DB_DATABASE=ZKT_ADMS_TEST`
- `.env.testing` also points testing to `ZKT_ADMS_TEST`

This separates test writes from the live/local runtime database.

---

## 12. Configuration

The main ADMS-specific configuration lives in:

- `config/zkteco.php`

Important settings include:

- device auto-registration
- allowed serial numbers / allowed IPs
- per-device endpoint overrides
- default port
- heartbeat TTL
- command retry timing
- automatic employee pull behavior
- auto cleanup of malformed legacy users
- timezone handling and option values
- face enrollment type
- device-side delete commands for employee cleanup
- webhook settings

Common environment variables are taken from:

- `.env`
- `.env.example`
- `.env.testing`

Other important config files:

- `config/database.php`
- `config/queue.php`
- `config/auth.php`
- `config/app.php`

---

## 13. Security and Operational Notes

These notes describe the current codebase state and are worth knowing before production deployment.

### 13.1 Admin route protection should be reviewed

`routes/web.php` currently registers the `/admin` routes without wrapping the group in explicit `auth` or `admin` middleware.

Also:

- `app/Http/Middleware/AdminMiddleware.php` currently passes requests through without role checks

That means access control should be verified and tightened before relying on route-level admin isolation in production.

### 13.2 Route home vs root redirect differ

- `RouteServiceProvider::HOME` is set to `/devices`
- the root `/` route redirects to `/admin/dashboard`

This is not fatal, but it is an implementation detail worth knowing because the project currently has both legacy and modern UI entry points.

### 13.3 Hybrid legacy/current data model

The repository contains both:

- newer normalized ADMS tables and services
- older attendance/report tables and controllers

When changing schema or writing new features, be careful not to assume that `attendances` and `attendance_logs` are the same thing.

### 13.4 Queue workers are strongly recommended

Some operations are designed to benefit from queues:

- attendance processing
- user sync preparation
- biometric template replay
- event-driven biometric synchronization

For production, run a proper queue worker instead of relying only on sync execution.

---

## 14. Related Project Documents

The repository already contains more specialized documents. This file should be used as the main overview, and the files below should be used for depth on specific topics.

- `README.md`
- `QUICK-START.md`
- `API.md`
- `ADMS-PROTOCOL.md`
- `DEPLOYMENT.md`
- `INDEX.md`
- `PROJECT-SUMMARY.md`
- `MODERNIZATION_GUIDE.md`
- `docs/ZKTECO_ADMS.md`

---

## 15. Quick File Reference

If you need to jump into the code quickly, start here:

- ADMS device entrypoint: `app/Http/Controllers/IclockController.php`
- protocol orchestration: `app/Services/ZKTecoService.php`
- payload parsing: `app/Services/ZKTecoPayloadParser.php`
- outbound command formatting: `app/Services/ZKTecoCommandFormatter.php`
- device management: `app/Services/DeviceService.php`
- employee management: `app/Services/EmployeeService.php`
- user sync: `app/Services/UserSyncService.php`
- biometric replay: `app/Services/BiometricSyncService.php`
- admin devices UI: `resources/views/admin/devices/*`
- admin employees UI: `resources/views/admin/employees/*`
- admin logs UI: `resources/views/admin/logs/index.blade.php`
- shared enterprise UI theme: `public/enterprise-ui.css`

---

## 16. Final Notes

This repository is best understood as an ADMS control plane with three parallel responsibilities:

1. ingest and normalize traffic from ZKTeco devices
2. provide a web control surface for operators
3. store enough raw and normalized state to replay, debug, and synchronize users and biometrics safely

For code changes, the most important files are usually:

- `routes/web.php`
- `routes/device.php`
- `config/zkteco.php`
- `app/Services/ZKTecoService.php`
- `app/Services/CommandService.php`
- `app/Services/UserSyncService.php`
- `app/Services/BiometricSyncService.php`

