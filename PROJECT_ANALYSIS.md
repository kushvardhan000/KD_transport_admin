# Project Analysis: Transport Management System

## 1. Tech Stack + Exact Package Versions

### Backend (composer.json constraints)
- PHP: ^8.2
- laravel/framework: ^11.0
- laravel/tinker: ^2.9
- maatwebsite/excel: ^3.1
- fakerphp/faker (dev): ^1.23
- laravel/pint (dev): ^1.13
- laravel/sail (dev): ^1.26
- mockery/mockery (dev): ^1.6
- nunomaduro/collision (dev): ^8.0
- phpunit/phpunit (dev): ^10.5
- spatie/laravel-ignition (dev): ^2.4

> composer.lock not present; exact resolved versions unavailable.

### Frontend (package.json constraints)
- vite: ^5.0
- @tailwindcss/vite: ^4.3.3
- tailwindcss: ^4.3.3
- @tailwindcss/forms: ^0.5.11
- @tailwindcss/typography: ^0.5.20
- alpinejs: ^3.15.12
- axios: ^1.6.4
- laravel-vite-plugin: ^1.0

> package-lock.json not present; exact resolved versions unavailable.

### Stack
- Framework: Laravel 11
- PHP: 8.2+
- Database: SQLite default (MySQL/MariaDB/PostgreSQL/SQL Server supported)
- Frontend: Tailwind CSS v4 + Alpine.js v3 + Vite 5
- Excel: Maatwebsite Excel v3 (PhpSpreadsheet)
- Testing: PHPUnit 10
- Session: database (encrypted, HTTP-only, SameSite=strict)
- Queue: database
- Cache: database

---

## 2. Folder/File Structure

```
D:\transport\
├── _cookies.txt                   # Browser cookie export (laravel_session + XSRF-TOKEN) — SECURITY RISK
├── _headers.txt                   # Captured HTTP headers (419 CSRF failure) — debug artifact
├── _login.html                    # Saved login page HTML (contains CSRF token) — debug artifact
├── _login_full.html               # Another saved login page — debug artifact
├── check_config.php               # Standalone script to inspect session/app config values
├── debug_breakeven.php            # Debug: breakeven profit logic
├── debug_created_at.php           # Debug: created_at handling
├── debug_filter.php               # Debug: transport log filtering
├── debug_test.php                 # Debug: factory + update + profit filter
├── debug_update.php               # Debug: update computed fields
├── debug_update2.php              # Debug: update with SQL query logging
├── find_comment.php               # Debug: PhpSpreadsheet Comment class discovery
├── find_comment2.php              # Debug: Worksheet/Cell comment method reflection
├── find_comment3.php              # Debug: spreadsheet instantiation + comment class
├── find_comment4.php              # Debug: Worksheet comment method signatures
├── vite.config.js                 # Vite: Laravel plugin + Tailwind CSS plugin, host 127.0.0.1
│
├── app/
│   ├── Exports/
│   │   └── TransportLogsExport.php        # Excel export with formulas + styled headers
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php             # Base controller
│   │   │   ├── DashboardController.php    # Dashboard aggregates + recent logs + cache invalidation
│   │   │   ├── AccountController.php      # Accounts CRUD (Super Admin only)
│   │   │   ├── AccountTransactionController.php # Account transaction CRUD (Super Admin only)
│   │   │   ├── TraceController.php        # Trace lookup page for transport log traceability
│   │   │   ├── TransportLogController.php # CRUD + 4 export endpoints; status filter fixed for Stringable comparison
│   │   │   ├── UserController.php         # CRUD + resetPassword + activate/deactivate
│   │   │   ├── SettingController.php      # Profile edit/update (Super Admin email/password)
│   │   │   ├── ActivityLogController.php  # Index + show (Super Admin only)
│   │   ├── InlineEntityController.php # AJAX create for branches & fuel stations (auth+active)
│   │   │   └── Auth/
│   │   │       ├── AuthenticatedSessionController.php # Login + logout
│   │   │       └── PasswordResetController.php        # Forgot + reset password
│   │   ├── Middleware/
│   │   │   ├── EnsureUserIsActive.php     # Blocks inactive users
│   │   │   ├── LogActivity.php            # Logs CRUD to activity_logs
│   │   │   ├── NoCache.php                # No-cache headers
│   │   │   └── RoleMiddleware.php         # Role checking
│   │   └── Requests/
│   │       ├── Auth/
│   │       │   ├── LoginRequest.php               # Login validation + failed login logging
│   │       │   ├── NewPasswordRequest.php         # Password reset validation
│   │       │   └── PasswordResetLinkRequest.php   # Forgot password validation
│   │       ├── Profile/
│   │       │   └── UpdateProfileRequest.php       # Super Admin can change email/password
│   │       ├── Account/
│   │   │   ├── StoreAccountRequest.php        # Account validation + null coercion for linked + bank fields
│   │       │   └── StoreAccountTransactionRequest.php # Transaction validation (branch_id, direction, amount, etc.)
│   │       └── Transport/
│   │           ├── StoreTransportLogRequest.php   # Create validation + auth
│   │           ├── UpdateTransportLogRequest.php  # Update validation + auth
│   │           └── TransportLogRules.php          # Shared validation rules trait
│   ├── Models/
│   │   ├── User.php                 # Authenticatable; roles; relationships
│   │   ├── Account.php              # Ledger account (fuel_station, motor_parts_shop, staff, company_expense)
│   │   ├── AccountTransaction.php   # Ledger transaction with running balance
│   │   ├── TransportLog.php         # Core model; SoftDeletes; computed totals
│   │   ├── Company.php              # Companies (also used as carriers)
│   │   ├── Branch.php               # Branches
│   │   ├── Vehicle.php              # Vehicles
│   │   ├── Driver.php               # Drivers
│   │   ├── FuelStation.php          # Fuel stations
│   │   ├── ExpenseCategory.php      # Expense categories
│   │   ├── ActivityLog.php          # Audit trail
│   │   ├── StationDebit.php         # Fuel station debits
│   │   ├── StationCredit.php        # Fuel station credits
│   │   └── StationBalance.php       # Fuel station balances
│   ├── Observers/
│   │   ├── TransportLogObserver.php # Recomputes totals on creating/updating; syncs StationDebit and account_transactions on created/updated; reverses on deleting; generates trace_code; invalidates dashboard cache
│   │   └── AccountTransactionObserver.php # Recalculates fuel settlement + invalidates dashboard cache on created/updated/deleted/restored
│   ├── Policies/
│   │   ├── UserPolicy.php           # Super Admin full; Admin read-only
│   │   ├── TransportLogPolicy.php   # Super Admin full; Admin view/create only
│   │   ├── ActivityLogPolicy.php    # Super Admin view only
│   │   ├── AccountPolicy.php        # Super Admin full
│   │   └── AccountTransactionPolicy.php # Super Admin full
│   ├── Providers/
│   │   └── AppServiceProvider.php   # Policies + custom Gates + observer registration
│   └── Console/
│       └── Commands/
│           └── VerifyAccountsIntegrity.php # Scans for orphaned/mismatched records and running-balance drift
│   └── Services/
│       ├── ActivityLogger.php       # Static helper for activity log entries
│       ├── TransportLogService.php  # computeTotals() for financial fields
│       ├── AccountLedgerService.php # create/reverse/delete transactions; balance summary; filtered transactions
│       └── FuelSettlementService.php # Recalculates fuel_paid_amount and fuel_payment_status from linked credit transactions
│
├── config/
│   ├── app.php                      # App config
│   ├── auth.php                     # Auth guards + password broker
│   ├── database.php                 # DB connections (sqlite default)
│   ├── session.php                  # Session config (database, encrypted)
│   ├── mail.php                     # Mail config (log default)
│   ├── cache.php                    # Cache config (database default)
│   ├── queue.php                    # Queue config (database default)
│   ├── logging.php                  # Logging config
│   ├── filesystems.php              # Filesystem config
│   └── services.php                 # Third-party service placeholders
│
├── database/
│   ├── factories/                   # 12 factories
│   ├── migrations/                  # 23 migrations
│   └── seeders/                     # DatabaseSeeder + 6 individual seeders
│
├── resources/
│   ├── css/app.css                  # Tailwind v4 + custom theme
│   ├── js/
│   │   ├── bootstrap.js             # Axios setup
│   │   └── app.js                   # Alpine: theme, layout, transportForm, fuelStationPicker, inlineCreate, toast
│   └── views/
│       ├── layouts/
│       │   ├── app.blade.php        # Main layout
│       │   └── auth.blade.php       # Auth layout
│       ├── components/
│       │   ├── layout/
│       │   │   ├── sidebar.blade.php
│       │   │   ├── header.blade.php
│       │   │   └── breadcrumb.blade.php
│       │   ├── accounts/
│       │   │   ├── account-type-icon.blade.php
│       │   │   ├── balance-summary-card.blade.php
│       │   │   ├── filters-bar.blade.php
│       │   │   ├── ledger-table.blade.php
│       │   │   └── transaction-form-modal.blade.php
│       │   │   ├── _inline-entity-modals.blade.php  # Inline modals to create branches/fuel stations without leaving the page
│       │   └── ui/
│       │       ├── badge.blade.php
│       │       ├── button.blade.php
│       │       ├── input.blade.php
│       │       ├── kpi-card.blade.php
│       │       ├── modal.blade.php
│       │       ├── select.blade.php
│       │       ├── table.blade.php
│       │       └── toast.blade.php
│       ├── accounts/
│       │   ├── index.blade.php           # Accounts hub (4 type cards + recent transactions)
│       │   ├── type-index.blade.php      # Per-type card grid + search/status filter
│       │   ├── show.blade.php            # Ledger: summary + filters + table + modal
│       │   ├── create.blade.php          # New account form
│       │   ├── edit.blade.php            # Edit account form
│       │   └── transactions/
│       │       └── edit.blade.php        # Edit transaction form
│       ├── dashboard/dashboard.blade.php
│       ├── auth/
│       │   ├── login.blade.php
│       │   ├── forgot-password.blade.php
│       │   └── reset-password.blade.php
│       ├── errors/
│       ├── logs/
│       │   ├── index.blade.php
│       │   ├── create.blade.php
│       │   ├── _form.blade.php
│       │   ├── edit.blade.php
│       │   ├── show.blade.php
│       │   ├── activity.blade.php
│       │   └── activity-show.blade.php
│       ├── trace/
│       │   └── show.blade.php        # Traceability lookup page for a single transport log
│       ├── users/
│       │   ├── index.blade.php
│       │   ├── create.blade.php
│       │   ├── _form.blade.php
│       │   ├── edit.blade.php
│       │   └── show.blade.php
│       └── settings/index.blade.php
│
├── routes/
│   ├── web.php                      # All routes
│   └── console.php                  # Artisan inspire + accounts:verify-integrity
│
├── tests/
│   ├── TestCase.php
│   ├── Unit/ExampleTest.php
│   └── Feature/
│       ├── ExampleTest.php
│       ├── ViewFoundationTest.php
│       ├── SettingsPermissionTest.php
│       ├── ProductionFeatureTest.php
│       ├── ObserverLedgerActivityTest.php
│       ├── ExcelExportTest.php
│       ├── DebugProfitFilterTest.php
│       ├── ComputedBreakdownTest.php
│       └── ActivityChangeTrackingTest.php
│       └── AccountLedgerTest.php
│
├── .env.example
├── composer.json
├── package.json
├── vite.config.js
└── README.md
```

---

## 3. Complete DB Schema

### users
- id (PK), name, email (unique), email_verified_at (nullable), password, role (enum: super_admin/admin, default admin), is_active (boolean, default true), remember_token, branch_id (FK → branches.id, nullable, nullOnDelete), created_at, updated_at
- Indexes: email (unique), branch_id

### password_reset_tokens
- email (PK), token, created_at (nullable)

### sessions
- id (PK), user_id (FK → users.id, nullable, indexed), ip_address (varchar 45, nullable), user_agent (text, nullable), payload (longText), last_activity (indexed)

### branches
- id (PK), name, code (unique), address (text, nullable), created_at, updated_at
- Indexes: name

### companies
- id (PK), name, slug (unique), gstin (nullable), contact_info (text, nullable), is_active (boolean, default true), created_at, updated_at
- Indexes: name

### expense_categories
- id (PK), name, slug (unique), is_active (boolean, default true), created_at, updated_at
- Indexes: name

### vehicles
- id (PK), vehicle_no (unique), owner_name (nullable), type (nullable), capacity_kg (decimal 12,2, nullable), mileage_baseline (decimal 12,2, nullable), is_active (boolean, default true), branch_id (FK → branches.id, nullable, nullOnDelete), created_at, updated_at
- Indexes: vehicle_no, branch_id

### drivers
- id (PK), name, license_no (unique), phone (nullable), vehicle_id (FK → vehicles.id, nullable, nullOnDelete), is_active (boolean, default true), branch_id (FK → branches.id, nullable, nullOnDelete), created_at, updated_at
- Indexes: name, branch_id

### fuel_stations
- id (PK), name, slug (unique), contact_info (text, nullable), address (text, nullable), is_active (boolean, default true), branch_id (FK → branches.id, nullable, nullOnDelete), created_at, updated_at
- Indexes: name, branch_id

### station_balances
- id (PK), fuel_station_id (FK → fuel_stations.id, cascadeOnDelete), total_amount (decimal 14,2, default 0), created_at, updated_at
- Unique: fuel_station_id

### station_credits
- id (PK), fuel_station_id (FK → fuel_stations.id, cascadeOnDelete), branch_id (FK → branches.id, nullable, nullOnDelete), amount (decimal 14,2), reference_type (nullable), reference_id (nullable), notes (text, nullable), created_by (FK → users.id, nullable, nullOnDelete), updated_by (FK → users.id, nullable, nullOnDelete), date (date), created_at, updated_at, deleted_at (SoftDeletes)
- Indexes: fuel_station_id, branch_id, (reference_type, reference_id)

### station_debits
- id (PK), fuel_station_id (FK → fuel_stations.id, cascadeOnDelete), branch_id (FK → branches.id, nullable, nullOnDelete), amount (decimal 14,2), reference_type (nullable), reference_id (nullable), notes (text, nullable), created_by (FK → users.id, nullable, nullOnDelete), updated_by (FK → users.id, nullable, nullOnDelete), date (date), created_at, updated_at, deleted_at (SoftDeletes)
- Indexes: fuel_station_id, branch_id, (reference_type, reference_id)

### transport_logs
- id (PK), date (date), vehicle_no, company, transport_name, logsheet_no (nullable, unique), trace_code (nullable, unique, varchar 20), destination (nullable), km (decimal 12,2, default 0), weight (decimal 12,2, default 0), to_bb_sale (decimal 14,2, default 0), paid_sale (decimal 14,2, default 0), to_pay (decimal 14,2, default 0), total_sale (decimal 14,2, default 0, computed), freight (decimal 14,2, default 0), loading (decimal 14,2, default 0), unloading (decimal 14,2, default 0), dd (decimal 14,2, default 0), tempu_expense (decimal 14,2, default 0), commission (decimal 14,2, default 0), total_expense (decimal 14,2, default 0, computed), profit (decimal 14,2, default 0, computed), diesel_advance (decimal 14,2, default 0), cash_advance (decimal 14,2, default 0), total_advance (decimal 14,2, default 0, computed), payment (decimal 14,2, default 0), fuel_station_name (nullable), fuel_station_balance (decimal 14,2, default 0), balance_vehicle_payment (decimal 14,2, default 0, computed), clearing_date (date, nullable), detail (text, nullable), remarks (text, nullable), mileage (decimal 12,2, nullable), dtg_office_expense (decimal 14,2, default 0), vehicle_id (FK → vehicles.id, nullable, nullOnDelete), company_id (FK → companies.id, nullable, nullOnDelete), carrier_id (FK → companies.id, nullable, nullOnDelete), branch_id (FK → branches.id, nullable, nullOnDelete), fuel_station_id (FK → fuel_stations.id, nullable, nullOnDelete), created_by (FK → users.id, nullable, nullOnDelete), updated_by (FK → users.id, nullable, nullOnDelete), created_at, updated_at, deleted_at (SoftDeletes)
- Indexes: date, vehicle_no, company, transport_name, logsheet_no, trace_code, created_by, updated_by, branch_id, fuel_station_id, (date, company), (date, vehicle_no), created_at, clearing_date, (created_at, profit), (created_at, company)
- Check constraints (MySQL/MariaDB): all numeric expense/sale fields >= 0

### activity_logs
- id (PK), user_id (FK → users.id, nullable, nullOnDelete), role (nullable), action, table_name (nullable), subject_type (nullable), record_id (nullable), record_summary (nullable), description (text, nullable), ip_address (nullable), user_agent (nullable), success (boolean, default true), method (nullable), url (nullable), old_values (json, nullable), new_values (json, nullable), changes (json, nullable), device_info (varchar 512, nullable), session_id (varchar 128, nullable), created_at (nullable)
- Indexes: user_id, action, created_at, (subject_type, record_id), role, success, method

### cache
- key (PK), value (mediumText), expiration (integer)

### cache_locks
- key (PK), owner (varchar), expiration (integer)

### jobs
- id (PK), queue (indexed), payload (longText), attempts (unsignedTinyInteger), reserved_at (nullable), available_at, created_at

### job_batches
- id (PK), name, total_jobs, pending_jobs, failed_jobs, failed_job_ids (longText), options (mediumText, nullable), cancelled_at (nullable), created_at, finished_at (nullable)

### failed_jobs
- id (PK), uuid (unique), connection, queue, payload (longText), exception (longText), failed_at (timestamp, useCurrent)

### migrations
- migration (PK), batch (integer)

### logsheet_imports
- id (PK), date_from (date, nullable), date_to (date, nullable), original_filename (string), file_path (string, nullable), uploaded_by (FK → users.id, nullable, nullOnDelete), row_count (unsignedInteger, default 0), consolidated_count (unsignedInteger, default 0), duplicate_count (unsignedInteger, default 0), invalid_count (unsignedInteger, default 0), status (string, default 'pending'), total_amount (decimal 14,2, default 0), total_booked_amount (decimal 14,2, default 0), total_diff (decimal 14,2, default 0), total_gross_wt (decimal 12,3, default 0), out_of_range_rows (unsignedInteger, default 0), skipped_out_of_range_groups (unsignedInteger, default 0), fully_out_of_range_groups (unsignedInteger, default 0), created_at, updated_at
- Indexes: uploaded_by

### logsheet_raw_rows
- id (PK), import_id (FK → logsheet_imports.id, cascadeOnDelete), log_sheet_no (string, nullable, indexed), raw_data (json), row_number_in_file (unsignedInteger), is_valid (boolean, default true), validation_error (text, nullable), created_at, updated_at
- Indexes: import_id, log_sheet_no

### logsheets
- id (PK), log_sheet_no (string, unique, indexed), date (date, nullable), vehicle_no (string, nullable), tprt_code (string, nullable), tprt_name (string, nullable), destination (string, nullable), sap_invoice_no (string, nullable), posting_date (date, nullable), bill_date (date, nullable), vendor_inv_no (string, nullable), total_gross_wt (decimal 12,3, default 0), total_booked_amount (decimal 14,2, default 0), total_actual_amount (decimal 14,2, default 0), total_diff (decimal 14,2, default 0), consignment_count (unsignedInteger, default 0), status (string, default 'pending'), cleared_at (datetime, nullable), cleared_by (FK → users.id, nullable, nullOnDelete), last_import_id (FK → logsheet_imports.id, nullable, nullOnDelete), fully_out_of_requested_range (boolean, default false), created_at, updated_at, deleted_at (SoftDeletes)
- Indexes: log_sheet_no, date, last_import_id, cleared_by, (date, log_sheet_no)

### logsheet_details
- id (PK), logsheet_id (FK → logsheets.id), log_sheet_no (string), date (date, nullable), invoice_no (string, nullable), inv_date (date, nullable), payer (string, nullable), payer_name (string, nullable), town (string, nullable), gross_wt (decimal 12,3, nullable), difference (decimal 12,3, nullable), amount (decimal 14,2, nullable), volume (decimal 12,3, nullable), tprt_code (string, nullable), tprt_name (string, nullable), container_id (string, nullable), destination (string, nullable), sap_invoice_no (string, nullable), posting_date (date, nullable), bill_date (date, nullable), vendor_inv_no (string, nullable), route (string, nullable), town_2 (string, nullable), gross_weight_2 (decimal 12,3, nullable), booked_amount (decimal 14,2, nullable), actual_rate (decimal 14,2, nullable), actual_amount (decimal 14,2, nullable), diff (decimal 14,2, nullable), cleared (boolean, default false), difference_placeholder (decimal 12,3, nullable), time (string, nullable), cust_group (string, nullable), no_of_packs (integer, nullable), extra_fields (json, nullable), created_at, updated_at
- Indexes: logsheet_id, log_sheet_no
- Casts: date, inv_date, posting_date, bill_date (date); gross_wt, difference, volume, gross_weight_2 (decimal:3); amount, booked_amount, actual_rate, actual_amount, diff, difference_placeholder (decimal:2); cleared (boolean); time, cust_group (string); no_of_packs (integer); extra_fields (array)

### logsheet_clearings
- id (PK), logsheet_id (FK → logsheets.id, cascadeOnDelete), cleared_by (FK → users.id, cascadeOnDelete), cleared_at (datetime), invoice_no_reference (string, nullable), notes (text, nullable), created_at, updated_at
- Indexes: logsheet_id, cleared_by

---

## 4. Seeders/Factories Summary

### Seeders
- **DatabaseSeeder**: 3 branches, 12 companies, 15 vehicles, 15 drivers, 7 expense categories, 8 fuel stations (each with 0 balance), 1 Super Admin (admin@sls.com/password), 2 Admins (admin1@sls.com/password, admin2@sls.com/password), 60 transport logs (with station debits for diesel advances), 30 activity logs.
- **BranchSeeder**: 3 branches.
- **CompanySeeder**: 12 hardcoded Indian logistics companies.
- **VehicleSeeder**: 15 vehicles.
- **DriverSeeder**: 15 drivers.
- **FuelStationSeeder**: 8 fuel stations.
- **ExpenseCategorySeeder**: 7 categories (Freight, Loading, Unloading, DD, Tempu Expense, Commission, DTG Office Expense).

### Factories
- **UserFactory**: fake name/email; password 'password'; states: superAdmin, admin, inactive.
- **BranchFactory**: fake city + 3-char code + address.
- **CompanyFactory**: fake company + logistics suffix; fake GSTIN regex.
- **VehicleFactory**: fake Indian vehicle number; types: Truck/Trailer/Tanker/Container/LCV/HCV; capacity_kg, mileage_baseline.
- **DriverFactory**: fake name; license_no regex; phone; vehicle + branch.
- **FuelStationFactory**: 8 brands + city name.
- **ExpenseCategoryFactory**: picks from 7 hardcoded names.
- **TransportLogFactory**: coherent financial data; 12 company names; 6 fuel station names; 70% clearing_date chance. States: loss, breakEven, overpaid.
- **StationBalanceFactory**: total_amount (-5000 to 50000). States: positive, negative.
- **StationCreditFactory**: reference_type (transport_log/invoice/manual), reference_id, notes, date.
- **StationDebitFactory**: same shape as StationCredit.
- **ActivityLogFactory**: random user + action + table_name + description + created_at.

---

## 5. All Routes Mapped to Controller@Method with Middleware

| Method | URI | Name | Controller@Method | Middleware |
|--------|-----|------|-------------------|-----------|
| GET | / | home | Closure | web |
| GET | /login | login | AuthenticatedSessionController@create | guest, web |
| POST | /login | — | AuthenticatedSessionController@store | guest, throttle:5,1, web |
| GET | /forgot-password | password.request | PasswordResetController@create | guest, web |
| POST | /forgot-password | password.email | PasswordResetController@store | guest, throttle:5,1, web |
| GET | /reset-password | password.reset | PasswordResetController@edit | guest, web |
| POST | /reset-password | password.store | PasswordResetController@update | guest, throttle:5,1, web |
| POST | /logout | logout | AuthenticatedSessionController@destroy | auth, no.cache, web |
| GET | /dashboard | dashboard | DashboardController@index | auth, active, no.cache, web |
| GET | /transport-logs | transport-logs.index | TransportLogController@index | auth, active, no.cache, web |
| GET | /transport-logs/create | transport-logs.create | TransportLogController@create | auth, active, no.cache, web |
| POST | /transport-logs | transport-logs.store | TransportLogController@store | auth, active, no.cache, web |
| GET | /transport-logs/{transport_log} | transport-logs.show | TransportLogController@show | auth, active, no.cache, web |
| GET | /transport-logs/{transport_log}/edit | transport-logs.edit | TransportLogController@edit | auth, active, no.cache, web |
| PUT/PATCH | /transport-logs/{transport_log} | transport-logs.update | TransportLogController@update | auth, active, no.cache, web |
| DELETE | /transport-logs/{transport_log} | transport-logs.destroy | TransportLogController@destroy | auth, active, no.cache, web |
| GET | /transport-logs/export/monthly | transport-logs.export.monthly | TransportLogController@exportMonthly | auth, active, no.cache, web |
| GET | /transport-logs/export/yearly | transport-logs.export.yearly | TransportLogController@exportYearly | auth, active, no.cache, web |
| GET | /transport-logs/export/range | transport-logs.export.range | TransportLogController@exportRange | auth, active, no.cache, web |
| GET | /transport-logs/{transport_log}/export/single | transport-logs.export.single | TransportLogController@exportSingle | auth, active, no.cache, web |
| GET | /settings | settings.edit | SettingController@edit | auth, active, no.cache, web |
| PATCH | /settings | settings.update | SettingController@update | auth, active, no.cache, web |
| GET | /users | users.index | UserController@index | auth, active, no.cache, role:super_admin, web |
| GET | /users/create | users.create | UserController@create | auth, active, no.cache, role:super_admin, web |
| POST | /users | users.store | UserController@store | auth, active, no.cache, role:super_admin, web |
| GET | /users/{user} | users.show | UserController@show | auth, active, no.cache, role:super_admin, web |
| GET | /users/{user}/edit | users.edit | UserController@edit | auth, active, no.cache, role:super_admin, web |
| PUT/PATCH | /users/{user} | users.update | UserController@update | auth, active, no.cache, role:super_admin, web |
| DELETE | /users/{user} | users.destroy | UserController@destroy | auth, active, no.cache, role:super_admin, web |
| POST | /users/{user}/reset-password | users.reset-password | UserController@resetPassword | auth, active, no.cache, role:super_admin, web |
| POST | /users/{user}/deactivate | users.deactivate | UserController@deactivate | auth, active, no.cache, role:super_admin, web |
| POST | /users/{user}/activate | users.activate | UserController@activate | auth, active, no.cache, role:super_admin, web |
| GET | /activity-logs | activity-logs.index | ActivityLogController@index | auth, active, no.cache, role:super_admin, web |
| GET | /activity-logs/{activity_log} | activity-logs.show | ActivityLogController@show | auth, active, no.cache, role:super_admin, web |
| GET | /accounts | accounts.index | AccountController@index | auth, active, no.cache, role:super_admin, web |
| GET | /accounts/create | accounts.create | AccountController@create | auth, active, no.cache, role:super_admin, web |
| POST | /accounts | accounts.store | AccountController@store | auth, active, no.cache, role:super_admin, web |
| GET | /accounts/{account} | accounts.show | AccountController@show | auth, active, no.cache, role:super_admin, web |
| GET | /accounts/{account}/edit | accounts.edit | AccountController@edit | auth, active, no.cache, role:super_admin, web |
| PUT/PATCH | /accounts/{account} | accounts.update | AccountController@update | auth, active, no.cache, role:super_admin, web |
| DELETE | /accounts/{account} | accounts.destroy | AccountController@destroy | auth, active, no.cache, role:super_admin, web |
| GET | /accounts/{account}/transactions/{transaction}/edit | accounts.transactions.edit | AccountTransactionController@edit | auth, active, no.cache, role:super_admin, web |
| POST | /accounts/{account}/transactions | accounts.transactions.store | AccountTransactionController@store | auth, active, no.cache, role:super_admin, web |
| PUT/PATCH | /accounts/{account}/transactions/{transaction} | accounts.transactions.update | AccountTransactionController@update | auth, active, no.cache, role:super_admin, web |
| DELETE | /accounts/{account}/transactions/{transaction} | accounts.transactions.destroy | AccountTransactionController@destroy | auth, active, no.cache, role:super_admin, web |
| GET | /accounts/transport-logs/search | accounts.transport-logs.search | AccountController@searchTransportLogs | auth, active, no.cache, role:super_admin, web |
| GET | /accounts/{account}/pump-flow | accounts.pump-flow | AccountController@pumpFlow | auth, active, no.cache, role:super_admin, web |
| GET | /trace/{trace_code} | trace.show | TraceController@show | auth, active, no.cache, web |
| GET | /403 | errors.forbidden | Closure | web |
| GET | /500 | errors.server | Closure | web |
| GET | /* (fallback) | — | Closure | web |

---

## 6. All Models with Relationships

### User
- Fillable: name, email, password, role, is_active, branch_id
- Hidden: password, remember_token
- Casts: email_verified_at (datetime), password (hashed), is_active (boolean)
- Constants: ROLE_SUPER_ADMIN = 'super_admin', ROLE_ADMIN = 'admin'
- Methods: isSuperAdmin(), isAdmin(), isActive(), initials()
- Relationships: hasMany TransportLog (created_by), hasMany ActivityLog, belongsTo Branch

### Account
- Fillable: type, name, linked_fuel_station_id, linked_driver_id, branch_id, contact_info, address, opening_balance, current_balance, is_active, metadata, aadhar_no, driving_license_no, bank_account_no, bank_ifsc_code, bank_name, created_by, updated_by
- Casts: opening_balance (decimal:2), current_balance (decimal:2), is_active (boolean), metadata (array)
- Relationships: belongsTo FuelStation, belongsTo Driver, belongsTo Branch, hasMany AccountTransaction
- Types: fuel_station, motor_parts_shop, staff, company_expense
- Staff-specific: `aadhar_no` (string, globally unique, 12 digits), `driving_license_no` (string, nullable unless is_driver or linked_driver_id is set)
- Bank details (fuel_station & staff): `bank_account_no` (string, nullable), `bank_ifsc_code` (string, nullable, format validated `^[A-Z]{4}0[A-Z0-9]{6}$` i.e. 4 letters + `0` + 6 alphanumeric; normalized to uppercase on validation), `bank_name` (string, nullable). All three optional; a client-side Alpine hint nudges completing all three when some are filled. Displayed masked on the show/pump-flow pages (e.g. account number "XXXX XXXX 4521", IFSC uppercased). See `StoreAccountRequest.php`, `resources/views/accounts/create.blade.php`, `resources/views/accounts/edit.blade.php`, `resources/views/accounts/show.blade.php`, and `resources/views/accounts/_pump-flow.blade.php`.

### AccountTransaction
- Fillable: account_id, branch_id, direction, amount, payment_mode, payment_plan, installment_no, installment_total, reference_type, reference_id, description, attachment_path, transaction_date, running_balance, created_by, updated_by
- Casts: amount (decimal:2), running_balance (decimal:2), transaction_date (date)
- SoftDeletes
- Relationships: belongsTo Account, belongsTo Branch, belongsTo User (creator), belongsTo User (editor)

### TransportLog
- Fillable: date, vehicle_no, company, transport_name, logsheet_no, destination, km, weight, to_bb_sale, paid_sale, to_pay, total_sale, freight, loading, unloading, dd, tempu_expense, commission, total_expense, profit, diesel_advance, cash_advance, total_advance, payment, fuel_station_name, fuel_station_balance, balance_vehicle_payment, clearing_date, detail, remarks, mileage, dtg_office_expense, created_by, updated_by, vehicle_id, company_id, carrier_id, branch_id, fuel_station_id, created_at, updated_at
- Casts: date, clearing_date (date); all decimals (decimal:2); deleted_at (datetime)
- SoftDeletes
- Relationships: belongsTo User (creator), belongsTo User (editor), belongsTo Vehicle, belongsTo Company (company_id), belongsTo Company (carrier_id), belongsTo Branch, belongsTo FuelStation
- Methods: profitColorClass(), statusBadge(), statusBadgeVariant(), computeTotals()

### Company
- Fillable: name, slug, gstin, contact_info, is_active
- Casts: is_active (boolean)
- Relationships: hasMany Vehicle, hasMany TransportLog (company_id), hasMany TransportLog (carrier_id)
- Scopes: scopeActive()

### Branch
- Fillable: name, code, address
- Relationships: hasMany User, hasMany Vehicle, hasMany Driver, hasMany FuelStation, hasMany TransportLog, hasMany StationCredit, hasMany StationDebit

### Vehicle
- Fillable: vehicle_no, owner_name, type, capacity_kg, mileage_baseline, is_active, branch_id
- Casts: is_active (boolean), capacity_kg (decimal:2), mileage_baseline (decimal:2)
- Relationships: belongsTo Branch, hasMany TransportLog, hasMany Driver
- Scopes: scopeActive()

### Driver
- Fillable: name, license_no, phone, vehicle_id, is_active, branch_id
- Casts: is_active (boolean)
- Relationships: belongsTo Vehicle, belongsTo Branch
- Scopes: scopeActive()

### FuelStation
- Fillable: name, slug, contact_info, address, is_active, branch_id
- Casts: is_active (boolean)
- Relationships: belongsTo Branch, hasMany StationBalance, hasMany StationCredit, hasMany StationDebit, hasMany TransportLog
- Scopes: scopeActive()

### ExpenseCategory
- Fillable: name, slug, is_active
- Casts: is_active (boolean)
- Scopes: scopeActive()

### ActivityLog
- Fillable: user_id, role, action, table_name, subject_type, record_id, record_summary, description, ip_address, user_agent, success, method, url, old_values, new_values, created_at, changes, device_info, session_id
- Casts: created_at (datetime); changes, old_values, new_values (array); success (boolean)
- No timestamps
- Relationships: belongsTo User

### StationDebit
- Fillable: fuel_station_id, branch_id, amount, reference_type, reference_id, notes, created_by, updated_by, date
- Casts: amount (decimal:2), date (date)
- SoftDeletes
- Relationships: belongsTo FuelStation, belongsTo Branch, belongsTo User (creator), belongsTo User (editor)

### StationCredit
- Fillable: fuel_station_id, branch_id, amount, reference_type, reference_id, notes, created_by, updated_by, date
- Casts: amount (decimal:2), date (date)
- SoftDeletes
- Relationships: belongsTo FuelStation, belongsTo Branch, belongsTo User (creator), belongsTo User (editor)

### StationBalance
- Fillable: fuel_station_id, total_amount
- Casts: total_amount (decimal:2)
- Relationships: belongsTo FuelStation

---

## 7. Dynamic UI Rendering (Blade + JS)

### Server-Side Data Flow
1. Request → Middleware (auth → active → no.cache → role)
2. Policy authorization (authorizeResource / explicit gates)
3. Eloquent query with eager loading
4. Pagination with query string preservation
5. View rendered with compact/array data
6. Blade extends `layouts.app`; content via `@yield('content')`

### Frontend Interactivity
- **Theme**: Alpine store toggles `dark` class on `<html>`, persists to localStorage
- **Layout**: Alpine data manages sidebar (open/icon-only), responsive at 768px, Escape closes mobile sidebar
- **Transport Form**: Alpine `transportForm` recalculates total_sale, total_expense, profit, total_advance, balance_vehicle_payment on every input
- **Toast**: Server emits `<meta name="flash-message">`; JS reads on DOMContentLoaded and renders dismissible toast
- **Export Dropdown**: Alpine toggle with click-away close
- **Modals**: Alpine event bus (`$dispatch('open-modal', 'id')`)
- **Confirmations**: Inline `onsubmit="return confirm(...)"` or Alpine `@submit.prevent`

### Data Flow
```
User → Route → Middleware → Controller → Policy → Eloquent → Blade (layout + components) → Vite-built CSS/JS → Browser
```

---

## 8. Full Feature List

### Authentication & Authorization
- Login (email/password, throttled 5/min)
- Session-based auth (database driver, encrypted, HTTP-only, SameSite=strict)
- Password reset (Laravel token broker, 60min expiry)
- Logout (session invalidate + CSRF regenerate)
- Roles: super_admin, admin
- Inactive user auto-logout (EnsureUserIsActive middleware)
- Super Admin can deactivate/activate/delete users (not other Super Admins or self for deactivate/delete)
- Activity logging for login, logout, failed login

### Transport Log Management
- Create/Edit/View/Delete transport logs (SoftDeletes)
- Computed fields enforced server-side: total_sale, total_expense, profit, total_advance, balance_vehicle_payment
- Live recalculation in UI via Alpine.js
- Search by vehicle_no / company / transport_name
- Filter by company, status (profit/loss/breakeven), created_date, clearing_date
- Sort by date, vehicle_no, company, total_sale, total_expense, profit
- Pagination (15 per page) with query string preservation
- Status badges (Cleared/Pending, Profit/Loss/Breakeven)
- Detail view with breakdown cards (Total Sale, Total Expense, Profit, Total Advance, Balance Vehicle Payment)

### Excel Export
- Single log export
- Monthly export (by month/year)
- Yearly export (by year)
- Custom range export (respects active filters)
- Formulas in computed columns (Total Sale, Total Expense, Profit, Total Advance, Balance Vehicle Payment)
- Color-coded headers and formula cells
- Header comments explaining formulas

### User Management (Super Admin only)
- Create/Edit/Delete users
- Reset user password (with current_password confirmation)
- Activate/Deactivate users
- Role assignment (admin/super_admin)

### Activity Logs (Super Admin only)
- Index with filters: action, user, role, module, description, record_id, date_from, date_to, success
- Show detail with changes diff (old → new)
- Tracks: login, logout, failed_login, CRUD operations, exports, password changes, role changes, status changes, profile updates

### Dashboard
- 9 KPI cards: Total Entries, Today's Entries, Today's Profit, Monthly Profit, Pending Vehicle Payments, Pending Fuel Balance, Pending Fuel Settlements, Total Advances, Total Expenses
- Pending Fuel Settlements shows count of unpaid/partial logs + combined remaining due
- Recent Transport Logs table (last 8)
- Recent Admin Actions feed (Super Admin only)
- Full Activity feed (Super Admin only)
- Cached for 1 minute globally; invalidated on transport log and account transaction changes

### Settings / Profile
- Update name (all active users)
- Super Admin can also update email and password
- Activity logging for profile updates, password changes

### Fuel Station Ledger (Implicit)
- StationDebit auto-created when transport log has diesel_advance > 0 and fuel_station_id
- StationDebit soft-deleted when diesel_advance becomes 0 or fuel_station_id removed
- StationDebit reversed on transport log delete
- Activity logging for all station debit operations

### Accounts Ledger (Super Admin only)
- 4 account types: fuel_station, motor_parts_shop, staff, company_expense
- Full CRUD for accounts with opening_balance and current_balance tracking
- Account transactions with running balance recalculation on create/edit/delete
- Transaction types: full, emi (with installments), partial
- Payment modes: cash, bank_transfer, upi, cheque, other
- Filter transactions by date range, direction, payment mode, payment plan, month/year
- Dashboard KPIs for fuel station dues, motor parts dues, monthly staff salary, monthly company expenses
- `lockForUpdate()` on Account row during all balance-affecting operations to prevent race conditions
- Backdated `transaction_date` insertions correctly recalculate all subsequent `running_balance` values
- `php artisan accounts:verify-integrity` command scans for orphaned fuel-station accounts, missing accounts, running-balance drift, and fuel-settlement drift
- Fuel station accounts use a simplified step-based flow (`accounts?type=fuel_station&selected={id}`) as the single canonical view; direct hits to `accounts/{id}` for fuel_station type redirect to that flow
- Edit and Delete actions are available inline on account detail views for all 4 types (fuel station pump header + account cards for other types)
- Staff accounts have mandatory identity fields: `aadhar_no` (required, exactly 12 digits, globally unique) and conditional `driving_license_no` (required when the staff member is marked as a driver via `is_driver` checkbox or when `linked_driver_id` is set). Aadhar is displayed masked on the show page (e.g. "XXXX XXXX 9012"). See `resources/views/accounts/create.blade.php`, `resources/views/accounts/edit.blade.php`, and `app/Http/Requests/Account/StoreAccountRequest.php`.
- Bank detail fields (`bank_account_no`, `bank_ifsc_code`, `bank_name`) added for `fuel_station` and `staff` account types. All three are optional; `bank_ifsc_code` is validated against `^[A-Z]{4}0[A-Z0-9]{6}$` (case-insensitive, normalized to uppercase on validation). A client-side Alpine hint nudges users to complete all three when some are filled (not hard validation). Account number is masked on display (e.g. "XXXX XXXX 4521") on the staff show page and the fuel-station pump-flow detail view. Migration: `2026_09_11_000000_add_bank_detail_fields_to_accounts_table`.

#### Custom Field Options (Super Admin only)
- A `custom_field_options` table (`2026_09_11_000001_create_custom_field_options_table.php`) stores extensible dropdown options for descriptive fields such as `payment_mode` and `payment_plan`.
- Structural enums (`Account.type`, `AccountTransaction.direction`) remain hardcoded in their respective request validators and are NOT managed through this table to preserve ledger integrity.
- The `CustomFieldController` exposes full CRUD (`/settings/custom-fields`) restricted to `super_admin` via the `super_admin` middleware.
- `CustomFieldOptionSeeder` migrates the legacy hardcoded values (cash, bank_transfer, upi, cheque, other for payment_mode; full, emi, partial for payment_plan) into the table for backward compatibility.
- `StoreAccountTransactionRequest` validates `payment_mode` and `payment_plan` against the active `custom_field_options` entries instead of hardcoded lists.
- All transaction forms (`transaction-form-modal.blade.php`, `accounts/transactions/edit.blade.php`) and the filters bar populate their `<select>` elements from the `custom_field_options` table.
- Deactivating an option removes it from new transactions without corrupting historical records.
- Tests: `tests/Feature/CustomFieldOptionsTest.php` (11 tests covering CRUD, authorization, validation, and historical integrity).

### Fuel Station Picker (Transport Log Forms)
- The `fuel_station_name` field on `resources/views/logs/_form.blade.php` has been replaced with a proper searchable dropdown (combobox/typeahead).
- The `fuelStationPicker` Alpine component lives in `resources/js/app.js` and is registered via `Alpine.data('fuelStationPicker', (config) => ({ ... }))`.
- The picker is fed a JSON-serialized list of **active fuel stations** (id, name, branch, current_balance) rendered server-side from `TransportLogController::formViewData()`. No AJAX calls — everything is preloaded for instant filtering.
- Features:
  - Searchable by name OR branch, with ↑/↓/Enter/Esc keyboard navigation
  - Below the field, shows the selected station's **current live ledger balance** (e.g. "Current balance: ₹12,345.67"), color-coded green/red
  - If no station matches, shows a "+ Add 'X' as a new station →" link. With JS enabled it opens an in-page modal (`add-fuel-station-modal`) to create the fuel station inline; the new station is appended to the picker list and auto-selected. If JS is disabled, the link falls back to opening `accounts.create?type=fuel_station&name=...` in a new tab.
  - Click-outside to close, Clear button to reset
  - Selected station's name is also auto-filled into the legacy `fuel_station_name` hidden input for display/backward compatibility
  - Hidden `fuel_station_id` input is the canonical source of truth
- Server-side validation in `TransportLogRules` ensures `fuel_station_id` resolves to a real `fuel_stations` row (`exists:fuel_stations,id`); friendly error message instead of DB crash on bad id.
- Legacy logs that still have a `fuel_station_name` string but no `fuel_station_id` display gracefully on the show page (no crash); the picker is empty by default and the user can re-link by selecting a station.
- On the show page, when `fuel_station_id` is set, the fuel station name is rendered as a **link to its ledger** (`accounts?type=fuel_station&selected={id}`).
- Tests: `tests/Feature/FuelStationComboboxTest.php` and `tests/Feature/FuelStationDisplayRegressionTest.php`.

### Inline Entity Creation (Branches & Fuel Stations)
- Lightweight modal flow (reusing the `<x-ui.modal>` component) lets users create a `branches` or `fuel_stations` row without leaving the current page.
- Triggered from the accounts create/edit forms via a `+` button next to each `linked_fuel_station_id` and `branch_id` dropdown, and from the transport-log fuel-station picker combobox.
- Backed by `app/Http/Controllers/InlineEntityController.php` and routes (Super Admin + Admin, `auth` + `active`):
  - `POST /entities/branches` (`entities.branches.store`) — creates a branch (name, code, address). `code` is upper-cased and uniqueness-validated.
  - `POST /entities/fuel-stations` (`entities.fuel-stations.store`) — creates a fuel station (name, branch_id, contact_info, address, is_active). `slug` is auto-generated (unique suffix added if needed).
- Both endpoints are AJAX (expect `X-Requested-With` + `Accept: application/json`); validation failures return a 422 JSON error payload that the modal renders inline.
- The `inlineCreate` Alpine component (in `resources/js/app.js`) submits the modal form via `fetch`, adds the new option to the target `<select>`, auto-selects it, closes the modal, and dispatches a window `entity-created` event.
- The transport-log picker (`fuelStationPicker`) listens for `entity-created` so a station created from its modal is appended to the live station list and auto-selected (with a balance default of 0).
- Graceful fallback: the picker's "Add new station" link keeps its `accounts.create?type=fuel_station` `target="_blank"` href and only swaps to the in-page modal when JS is available (`@click.prevent="$dispatch('open-modal', 'add-fuel-station-modal')"`), so the new-tab form still works if JS fails.
- Shared modal markup lives in `resources/views/accounts/_inline-entity-modals.blade.php` (both `add-branch-modal` and `add-fuel-station-modal`). The fuel-station modal itself contains a "+ Add New Branch" trigger.
- Tests: `tests/Feature/InlineEntityCreationTest.php`.

### Traceability
- Each transport log gets a unique `trace_code` (format `TL-000001`) on creation
- Trace code displayed prominently on transport log show page with a Copy button
- `GET /trace/{trace_code}` lookup page accessible to any authenticated active user
- Trace page shows consolidated reconciliation: transport log financials, linked fuel station name + current balance, fuel_payment_status, full payment history
- Green "Consistent" / red "Discrepancy Found" badge based on real-time audit: does `fuel_paid_amount` equal sum of linked credit transactions, and does `current_balance` match computed running balance?
- "View full ledger →" link on transport log edit form's fuel settlement card navigates directly to the linked fuel station's account ledger

### Multi-Payment Fuel Settlement Tracking
- transport_logs table has cached fuel_paid_amount (decimal 14,2) and fuel_payment_status (nullable enum: unpaid/partial/paid/overpaid, or NULL when the log has no fuel component)
- FuelSettlementService::recalculateForLog() is the single source of truth for these cached fields. It is called from:
  1. AccountTransactionObserver (created/updated/deleted/restored of any linked credit transaction)
  2. TransportLogObserver::created/updated/restored (to handle edits to diesel_advance, fuel_station_id changes, and restore-after-soft-delete)
- Transport log show page shows "Payment History for This Log" panel with status badge, progress bar, and chronological transaction list
- "Record Payment" button on transport log show page opens transaction modal pre-linked to that log
- Transport log edit page shows compact read-only fuel settlement status and payment history
- Master-detail fuel station accounts page (`accounts?type=fuel_station`) with Alpine.js two-pane layout: pump picker list + live ledger fragment. Transactions can be initiated directly from the pump detail view via the "+ Add Transaction" button, which opens the same `transaction-form-modal` component used elsewhere. The modal pre-fills `account_id` to the selected pump, supports optional transport log linking via the existing `/accounts/transport-logs/search` type-ahead, and calls the exact same `AccountLedgerService::createTransaction()` path as all other entry points.
- Transport log search endpoint (GET /accounts/transport-logs/search) for linking payments to logs via type-ahead; returns `destination` and `driver_name` (from the linked vehicle's first driver) in addition to the existing fields
- Supports flexible multi-part settlements: pay nothing first, partial second, rest on third — each as separate account_transaction
- AccountTransaction mirroring: creates debit transactions in account_transactions table for diesel advances; recalculates running balances on changes
- Fuel Station Ledger section rendered on transport log show page via AccountLedgerService::getBalanceSummary() and getFilteredTransactions()

#### Finalized Edge-Case Policies (after `tests/Feature/FuelSettlementEdgeCasesTest.php` audit)

| # | Scenario | Behavior |
|---|----------|----------|
| 1 | Transport log with `diesel_advance = 0` and no `fuel_station_id` | `fuel_payment_status` is set to **NULL** (not `'unpaid'`). "Unpaid" implies money is owed; NULL means "this log has no fuel component". `fuel_paid_amount` is 0. |
| 2 | Admin edits `diesel_advance` upward after payments exist (e.g. 5000 paid in full, then advance raised to 8000) | The transport log observer detects the change, calls `recalculateSettlement()`. Status correctly reverts to **`partial`**, `fuel_paid_amount` unchanged, due = 3000. |
| 3 | Admin edits `diesel_advance` downward below what was already paid (e.g. paid 6000 of 5000, then reduce advance to 4000) | Status correctly shows **`overpaid`** by 2000. No errors, math is correct. |
| 4 | Transport log is **soft-deleted** while it has active linked payments | **Policy: keep the account_transactions in place.** They are historical ledger entries for the fuel station; deleting them would corrupt the pump's balance history. The transport log's settlement view naturally disappears with it. The station's `current_balance` is unchanged. See `test_soft_deleting_log_preserves_account_transactions_and_balances`. |
| 5 | Transport log is **restored** (un-soft-deleted) | The `restored` observer event triggers `recalculateSettlement()`, which recomputes `fuel_paid_amount` and `fuel_payment_status` from currently-existing (non-deleted) credit transactions. Any transactions created or deleted while the log was soft-deleted are picked up. |
| 6 | Two different logs accidentally linked to the same `(reference_type, reference_id)` | **Impossible by design.** The pair `(reference_type='App\\Models\\TransportLog', reference_id=<id>)` is treated as a strict unique logical key — each credit transaction can only ever be linked to one transport log. There is no share-collision possible. See `test_reference_pair_is_strict_per_log`. |
| 7 | Out-of-order deletes (e.g. delete the earliest payment when later ones still exist, producing a state that would imply negative balance) | Recalculation is always driven by `SUM(amount)` of currently-non-deleted credits. The status is correctly computed each time: `overpaid` if paid > advance, `paid` if equal, `partial` if paid > 0, `unpaid` if paid = 0. The fuel station account's `current_balance` is clamped at 0 (you can't owe a pump money). |
| 8 | Concurrent payment attempts on the same log from two browser tabs | The `AccountLedgerService::createTransaction()` method acquires a `lockForUpdate()` on the Account row inside `DB::transaction()`. Combined with the `recalculateSettlement()` call from `AccountTransactionObserver::created`, this serializes concurrent writes so the cached `fuel_paid_amount` and `current_balance` always reflect the actual committed state. Verified by `test_concurrent_payments_are_safely_serialized`. |
| 9 | Admin changes a log’s `fuel_station_id` from an old station to a new station with no existing payments, while the diesel advance remains the same | The transport-log observer reverses the old station’s mirrored diesel-advance debit by soft-deleting the old `AccountTransaction` on the old station’s linked fuel account, then creates the new mirrored debit on the new station’s linked fuel account for the same submitted `diesel_advance` value inside the same database transaction. The new station’s `current_balance` and cached fuel settlement are recalculated immediately. |
| 10 | Admin changes both `fuel_station_id` and `diesel_advance` in one edit, while old payments already exist on the log | The reassignor uses the currently submitted `diesel_advance` amount when creating the mirrored debit on the new station, and it reverses any existing payment credits on the old station’s account before resetting the log settlement fields to zero. The old station debit is removed from the old account and the new debit is created on the new account in the same transaction, preventing double-application or stale-amount drift. |
| 11 | Admin clears `fuel_station_id` entirely (sets it to `null`) | The old station’s mirrored diesel-advance debit is soft-deleted through the same `reverseTransaction()` ledger path, no new account debit is created for an empty fuel station, and the cached `fuel_paid_amount` is forced back to `0` while `fuel_payment_status` is reset to `NULL` because the log no longer carries a fuel component. |
| 12 | Admin reassigns a log with prior real payment credits recorded against the old station account | The safest policy is to treat those payments as having been recorded against the wrong station because the log’s station was wrong. They are reversed from the old station’s linked account via the same soft-delete ledger path, the reassignment is logged audibly with `ActivityLogger`, and the cached `fuel_paid_amount` / `fuel_payment_status` reset to zero so the user must re-record payments on the new fuel station if the settlement should continue. |

### Integration Fixes Applied
- **LogsheetController@clearLogsheet**: Fixed leading-zero matching in clearing input — invoice displays log sheet numbers with leading zeros (e.g. `0045350959`) but the DB stores them without (e.g. `45350959`). Added `ltrim($request->input('log_sheet_no'), '0')` normalization before validation and lookup, with empty-string fallback for all-zero inputs. Also added `$request->merge()` to ensure the `exists` validator checks against the normalized value.
- **resources/views/logsheets/show.blade.php**: Fixed `data_get()` lookup keys in raw consignment rows table — used original Excel column names (`Invoice No`, `Payer`, `Payer Name`, `Town`, `Volume`) but raw_data is stored with canonical keys (`invoice_no`, `payer`, `payer_name`, `town`, `volume`), causing all 6 data columns to display as blank. Changed to canonical keys.
- **LogsheetImportService@import**: Added file persistence — uploaded Excel files are now stored to `storage/app/public/logsheets/` via `$file->store('logsheets', 'public')`, with the relative path saved in `logsheet_imports.file_path`.
- **LogsheetController@destroy**: Added delete endpoint (`DELETE /logsheets/{logsheet}`) that soft-deletes the logsheet; `LogsheetObserver` cascades to raw rows and clearings.
- **LogsheetController@download**: Added download endpoint (`GET /logsheets/download/{logsheetImport}`) to retrieve the original uploaded Excel file.
- **LogsheetObserver**: New observer registered in `AppServiceProvider` that handles cascade delete on Logsheet soft-delete: deletes all `LogsheetRawRow` records matching the log_sheet_no, deletes all `LogsheetClearing` records, and deletes the `LogsheetImport` record (and its stored file) when no non-deleted logsheets remain for that import.
- **routes/logsheets.php**: Added `DELETE /logsheets/{logsheet}` → `LogsheetController@destroy` and `GET /logsheets/download/{logsheetImport}` → `LogsheetController@download`.
- **resources/views/logsheets/index.blade.php**: Added delete button (with confirm) on each logsheet row and download link showing original filename.
- **LogsheetImport model**: Added `file_path` to `$fillable` and `logsheets()` relationship.
- **Logsheet model**: Added `lastImport()` relationship (already existed) used by observer for cascade deletion of import metadata when last logsheet is removed.
- **database/migrations/2026_09_15_000001_add_file_path_to_logsheet_imports.php**: New migration adding `file_path` column to `logsheet_imports`.
- **app/Observers/LogsheetObserver.php**: New observer for cascade delete and file cleanup.
- **app/Providers/AppServiceProvider.php**: Registered `Logsheet::observe(LogsheetObserver::class)`.
- **LogsheetImportService@parseDate**: Fixed Excel serial date handling — PhpSpreadsheet's `Excel::toArray()` returns date cells as Excel serial integers (e.g. `46182` for 2026-06-09), not `DateTime` objects. The old `parseDate()` used `Carbon::createFromFormat('Y-m-d', $value)` on numeric values, which threw `Carbon\Exceptions\InvalidFormatException` ("The separation symbol could not be found"). Since the entire import runs inside `DB::transaction()`, this exception caused a full rollback and a 500 error on upload — the feature appeared completely broken. Fix: numeric values are now converted via `Carbon::createFromFormat('Y-m-d', '1899-12-30')->addDays($value)`. Also added handling for Excel "null date" strings (`00.00.0000`) which previously parsed as garbage dates.
- **TransportLogController**: Fixed status filter (`profit`/`loss`/`breakeven`) by casting `$request->string('status')` to native string before comparison (Stringable object !== string)
- **TransportLogObserver**: Split event handlers so `creating`/`updating` recompute totals, while `created`/`updated` sync StationDebit and mirror AccountTransaction with correct model ID; added `saved` handler to invalidate dashboard cache; added `creating` handler to auto-generate `trace_code`; `mirrorToAccountTransactions` now uses `lockForUpdate` on Account row and delegates to `recalculateRunningBalances()` for correct backdated-transaction handling
- **TransportLog model**: Added `created_at` and `updated_at` to `$fillable` to allow test backdating and observer preservation through `forceFill`; added `trace_code` to `$fillable`
- **ProductionFeatureTest**: Fixed filter combination test to zero out all expense fields so `profit > 0` matches the `status=profit` filter
- **StoreAccountRequest**: Fixed `prepareForValidation()` TypeError by passing `[$field => null]` array to `merge()` instead of two separate arguments
- **AccountController**: Added `transactions` eager load to prevent N+1 on type-index page; removed pointless `?? true` on `isEmpty()` bool in index view
- **AccountLedgerService**: Fixed balance formula inconsistency — `getBalanceSummary()` now recalculates running balance using the same `max(0, balance - amount)` clamping as `createTransaction()`, so summary matches `current_balance`; `createTransaction()` and `reverseTransaction()` now lock the Account row with `lockForUpdate()` and recalculate all running balances via `recalculateRunningBalances()`; removed stale `$previousBalance` read outside DB transaction
- **transaction-form-modal**: Added missing `branch_id` required select field; fixed infinite recursion in `@submit.prevent` by removing erroneous `$refs.form.requestSubmit()` call; added `enctype="multipart/form-data"` and inline validation error displays
- **transactions/edit**: Fixed broken direction toggle — replaced duplicate `paymentPlan` assignments with proper `direction` Alpine variable and `x-model` binding
- **Accounts views**: Added validation error summary blocks and inline `@error` displays to `create`, `edit`, `transactions/edit`, and `transaction-form-modal` so users see server-side validation failures instead of silent reloads
- **FuelSettlementService + migration**: Added `fuel_paid_amount` and `fuel_payment_status` cached columns to `transport_logs`; service recalculates from credit transactions linked via polymorphic reference
- **AccountTransactionObserver**: Auto-recalculates transport log fuel settlement whenever a linked credit transaction is created/updated/deleted/restored; invalidates dashboard cache keys after every recalculation
- **TransportLogController@show**: Replaced generic "Fuel Station Ledger" section with dedicated "Payment History for This Log" panel showing status badge, progress bar, and transaction history via FuelSettlementService
- **logs/_form.blade.php**: Added compact read-only fuel settlement widget on edit pages so admins see payment status without leaving the form; added "View full ledger →" link inside the fuel settlement card that navigates to the linked fuel station's account ledger when `fuel_station_id` is set
- **DashboardController**: Changed aggregate cache key from per-user to global (`dashboard.aggregates`) since aggregates are user-independent; added cache invalidation for all dashboard keys on transport log and account transaction changes
- **VerifyAccountsIntegrity command**: Added `php artisan accounts:verify-integrity` that scans for orphaned fuel-station account references, fuel stations with transport logs but no Account, running-balance drift across all transactions, and fuel-settlement drift between cached `fuel_paid_amount` and actual credit transaction sums
- **TraceController + trace show view**: Added `GET /trace/{trace_code}` route and `TraceController@show` that loads a transport log by trace code and renders a consolidated reconciliation view with Consistency/Discrepancy indicator
- **trace_code migration**: Added `trace_code` (varchar 20, nullable, unique) column to `transport_logs` table; auto-generated as `TL-` + zero-padded 6-digit ID on transport log creation
- **Users page bug fix**: Removed `.e2e/seed.php` dev artifact that was creating an extra `E2E Admin` user outside of `DatabaseSeeder`, causing `/users` to show more than the expected 3 login-capable users; reseeding now produces exactly 1 super admin + 2 admins
- **Fuel station empty state fix**: Replaced confusing near-blank table state in `accounts/_pump-flow.blade.php` with a friendly empty-state card (icon + message + "Record Transaction" button) styled consistently with other empty states in the app; moved transaction modals in `accounts/type-index.blade.php` outside the `x-show="!selectedId"` container so they remain available when a pump is selected
- **AccountController show redirect for fuel_station**: `AccountController@show` now redirects `fuel_station` type accounts to the step-based pump flow (`accounts?type=fuel_station&selected={id}`) instead of rendering the generic full ledger page, making the step-based view the single source of truth for fuel station ledgers
- **Inline edit/delete on account detail views**: Added Edit (pencil icon) and Delete (trash icon) buttons to the fuel station pump detail header and to every non-fuel-station account card in `accounts/type-index.blade.php`, linking to the existing `accounts.edit` and `accounts.destroy` routes with standard confirm dialogs
- **AccountFactory default type**: Changed default `type` from random element to `motor_parts_shop` to prevent flaky test failures caused by random `fuel_station` accounts hitting the new redirect

---

## 8. Logsheet Import & Clearing

### Schema
The project now includes a dedicated logsheet-ingestion pipeline that mirrors the existing Excel-first import workflow without mutating the canonical `transport_logs` table. The formal storage model is split into five physical objects:

- `logsheet_imports` stores the upload metadata: `date_from`, `date_to`, `original_filename`, `file_path`, `uploaded_by`, `row_count`, `consolidated_count`, `duplicate_count`, `invalid_count`, `out_of_range_rows`, `skipped_out_of_range_groups`, `fully_out_of_range_groups`, and `status` (`pending`, `completed`, `invalid`). Each import is owned by the uploading `User`.
- `logsheet_raw_rows` persists every source row from the Excel file as a raw JSON payload, keyed by `import_id`, `row_number_in_file`, and `log_sheet_no`. It also carries `is_valid` and `validation_error` columns so a row can be saved as a failure record instead of being silently dropped.
- `logsheets` is the consolidated ledger of grouped logsheet summaries. It stores the canonical `log_sheet_no` and the rolled-up totals (`total_gross_wt`, `total_booked_amount`, `total_actual_amount`, `total_diff`) plus the date and other document metadata such as `vehicle_no`, `tprt_code`, `tprt_name`, `destination`, `sap_invoice_no`, `posting_date`, `bill_date`, `vendor_inv_no`, and a `fully_out_of_requested_range` boolean flag.
- `logsheet_details` holds the per-consignment row data for each consolidated logsheet, including all canonical columns plus a JSON `extra_fields` column that captures any non-canonical columns found in the source file (e.g., `Time`, `Cust Group`, `No of Packs`, or any future columns not in the core whitelist).
- `logsheet_clearings` captures the audit trail for a clearing action (`invoice_no_reference`, `notes`, `cleared_by`, `cleared_at`) and is linked back to a specific `Logsheet` row.

The concrete Eloquent objects are in the namespace `App\Models`: `LogsheetImport`, `LogsheetRawRow`, `Logsheet`, `LogsheetDetail`, and `LogsheetClearing`. They are wired with `belongsTo()` and `hasMany()` relationships so the controller can present both import metadata and raw-row evidence on the detail page.

### Service
The import engine is `App\Services\LogsheetImportService`. Its `import(UploadedFile $file, $dateFrom = null, $dateTo = null)` method performs the following lifecycle:

1. Starts a `DB::transaction()` and creates a `LogsheetImport` row with `status = pending` and the originating filename (stored to `storage/app/public/logsheets/`).
2. Reads the spreadsheet with `Maatwebsite\Excel\Facades\Excel::toArray()`, validates the required column headers (core whitelist: `Log Sheet No`, `Date`, `Tprt Code`, `Tprt Name`, `Container ID`, `Destination`, `SAPInvoiceNo`, `Posting Date`, `Bill Date`, `VendorInvNo`, `Actual Rate`, `Invoice No`, `Inv-Date`, `Payer`, `Payer Name`, `Town`, `Volume`, `Gross Wt`, `Booked Amount`, `Actual Amount`, `Diff`), and short-circuits with an `invalid` import status if the workbook lacks any of them.
3. **Auto-detects date range**: If `date_from`/`date_to` are not provided, the service scans all valid rows for the minimum and maximum `Date` values and uses those as the import's effective date range. The `LogsheetImport` record's `date_from`/`date_to` are updated accordingly.
4. Iterates row-by-row, normalizes the payload into the `raw_data` field (including all columns, both canonical and extra), validates `log_sheet_no` presence, and persists invalid rows as `LogsheetRawRow` records with `is_valid = false` and a `validation_error` message.
5. **Flexible column handling**: Any column in the header row that does not match a known canonical name is captured as an "extra field" — its raw (trimmed) header text becomes the key, and its value is stored in the `extra_fields` JSON column on `LogsheetDetail` for that row. This is in addition to `raw_data` on `LogsheetRawRow`, not a replacement. Core required columns (those driving totals and business logic) are still enforced; only unknown columns go to `extra_fields`.
6. Groups valid rows by `log_sheet_no` and computes the per-sheet totals by summing `Gross Wt`, `Booked Amount`, `Actual Amount`, and `Diff` values, then uses `Logsheet::updateOrCreate()` to write the consolidated row atomically. Rows outside the import's date range are tracked in `out_of_range_rows` and `skipped_out_of_range_groups`/`fully_out_of_range_groups` on the import.
7. Stores raw row audits on `logsheet_raw_rows` and updates the import metadata (`consolidated_count`, `invalid_count`, `duplicate_count`) before finishing. The `parseDate()` helper normalizes Excel date cells and numeric date-token values into a `Y-m-d` string.

The service is intentionally conservative: it records all import evidence in normalized raw rows and never drops a file without leaving a trace in the import metadata and raw-row model.

### Routes
The logsheet route group is intentionally scoped behind the existing Super Admin authority gate and no-cache middleware:

```php
Route::middleware(['auth', 'active', 'no.cache', 'role:super_admin'])->group(function () {
    Route::get('/logsheets', [LogsheetController::class, 'index'])->name('logsheets.index');
    Route::post('/logsheets', [LogsheetController::class, 'store'])->name('logsheets.store');
    Route::get('/logsheets/records', [LogsheetController::class, 'records'])->name('logsheets.records');
    Route::get('/logsheets/imports/{import}', [LogsheetController::class, 'importShow'])->name('logsheets.imports.show');
    Route::get('/logsheets/{logsheet}', [LogsheetController::class, 'show'])->name('logsheets.show');
    Route::delete('/logsheets/{logsheet}', [LogsheetController::class, 'destroy'])->name('logsheets.destroy');
    Route::delete('/logsheets/imports/{import}', [LogsheetController::class, 'destroyImport'])->name('logsheets.imports.destroy');
    Route::post('/logsheets/clear', [LogsheetController::class, 'clearLogsheet'])->name('logsheets.clear');
    Route::post('/logsheets/clear/preview', [LogsheetController::class, 'clearPreview'])->name('logsheets.clear.preview');
    Route::post('/logsheets/clear/bulk', [LogsheetController::class, 'clearBulk'])->name('logsheets.clear.bulk');
});
```

The route surface supports the primary UX: upload an Excel workbook (`POST /logsheets`), list the consolidated logsheet summaries (`GET /logsheets`), view all individual logsheets with filters (`GET /logsheets/records`), inspect an import's detail with its logsheets (`GET /logsheets/imports/{import}`), inspect one grouped logsheet with its raw consignment rows (`GET /logsheets/{logsheet}`), delete a single logsheet (`DELETE /logsheets/{logsheet}`), delete an entire import with cascade (`DELETE /logsheets/imports/{import}`), and record a clearing event (`POST /logsheets/clear` plus preview/bulk endpoints).

### UI
The UI is deliberately simple and visible to the admin team:

- `resources/views/logsheets/index.blade.php` renders a searchable/filterable summary page with:
  - an upload form that accepts `.xlsx`, `.xls`, and `.csv` files, with optional From/To date pickers (if omitted, the service auto-derives the range from the file),
  - a Clear Payments chip-input panel (partials/clear-payments.blade.php) for bulk clearing by log sheet number with optional date range,
  - a table of consolidated logsheets (imports) with Period, Total Amount, Status badge (Cleared / Partially cleared X/Y / Pending), and Actions (View, Delete).
- `resources/views/logsheets/records.blade.php` renders a filterable table of individual consolidated logsheets with Log Sheet No (link to show), Import Period, Total Amount, Status badge (Cleared/Pending), and Actions (View, Delete).
- `resources/views/logsheets/imports/show.blade.php` renders the import detail page with header summary, consolidated logsheets table, and an invalid-rows section with Alpine collapse.
- `resources/views/logsheets/show.blade.php` renders the per-logsheet detail page; it presents the header values and a raw-row table. **Dynamic columns**: the consignment rows table now renders one column per distinct key found across `extra_fields` for that logsheet's detail rows (union of keys actually present, not a hardcoded list), positioned after the existing fixed columns. If a given row doesn't have a value for a given dynamic column, an em dash (`—`) is rendered. The table is horizontally scrollable (`overflow-x-auto`) so an arbitrary number of dynamic columns doesn't break mobile layout.
- `resources/views/components/layout/sidebar.blade.php` exposes a `Logsheets` navigation entry so the workflow remains reachable from the main app shell.

The controller behind the screens is `App\Http\Controllers\LogsheetController`, which coordinates `index()`, `store()`, `records()`, `importShow()`, `show()`, `destroy()`, `destroyImport()`, `clearLogsheet()`, `clearPreview()`, and `clearBulk()` against `LogsheetImportService`, `LogsheetClearingService`, and the relevant Eloquent models.

---

## 9. Security / Config Concerns

### Critical
1. **Leaked session cookies in repo**: `_cookies.txt` contains live `laravel_session` and `XSRF-TOKEN` values for `127.0.0.1`. Anyone with access to this repo can hijack authenticated sessions.
2. **Leaked CSRF token in saved HTML**: `_login.html` and `_login_full.html` contain a live `csrf-token` meta tag value (`ef8RVhcFaVxT8h9WtYJE3ghv1DmXSQxqyvbIpUi3` and `85Moeu1BWKMrQK4AIlab5JTKTNsubNA5Zbb0Rn9U`). These are session-specific tokens.
3. **Debug scripts in web root**: `debug_*.php` and `find_comment*.php` are accessible via HTTP if the web server serves them. They bootstrap Laravel and could leak data or be used for further exploitation.
4. **check_config.php in web root**: Accessible via HTTP; exposes session domain, secure flag, APP_URL, session cookie name.

### High
5. **Default Super Admin credentials in seeder**: `admin@sls.com` / `password`. If seeders are run in any environment (including production), this creates a known backdoor account.
6. **APP_DEBUG=true in .env.example**: If copied to production without changing, detailed error pages with stack traces are exposed.
7. **SESSION_SECURE_COOKIE=false in .env.example**: Cookies sent over HTTP. Should be `true` in production.
8. **MAIL_FROM_ADDRESS=hello@example.com**: Generic placeholder; if not changed, outbound mail may be rejected or flagged.

### Medium
9. **No HTTPS enforcement**: No `\App\Http\Middleware\TrustProxies` customization visible; if behind a proxy, secure cookies may break.
10. **Activity logs store full user_agent + URL + IP**: Privacy consideration; ensure compliance with local regulations.
11. **Password reset throttle is 60 seconds** (`auth.php` passwords.throttle): Reasonable, but worth noting.
12. **composer.lock and package-lock.json missing**: Reproducibility risk; `npm install` / `composer install` may resolve to newer patch versions than tested.

### Low
13. **No CSP headers configured**: Not a direct vulnerability in this app, but missing defense-in-depth.
14. **Vite dev server bound to 127.0.0.1**: Correct for local dev, but ensure not accidentally exposed.

---

## 10. Testing Coverage

### Unit
- `ExampleTest.php`: Trivial assertion (true is true).

### Feature
- `ViewFoundationTest.php`: Login page public; protected pages redirect guests; Super Admin access all areas; Admin blocked from super_admin areas.
- `SettingsPermissionTest.php`: Admin cannot change email/password; Super Admin can; all can update name.
- `ProductionFeatureTest.php`: Comprehensive smoke tests covering auth, logout (session invalidation, CSRF regeneration, activity logging, 204 for JSON, no-cache headers), authorization policies, transport log CRUD (including computed field enforcement, negative profit), search/filter/sort/pagination (company, status, created_date, clearing_date, filter combinations, invalid dates, query string preservation), dashboard, users CRUD, settings, activity logs, error pages.
- `ObserverLedgerActivityTest.php`: Creating transport log with diesel_advance logs station_debit activity with correct changes payload; mirrored account transaction exists with correct reference_id.
- `ExcelExportTest.php`: Single/monthly/yearly/range exports; formula presence in all 5 computed columns; filter respect; authentication requirement.
- `DebugProfitFilterTest.php`: Profit/loss filter correctness.
- `ComputedBreakdownTest.php`: Show/edit page breakdown text presence; Excel formulas and header comments for all computed columns.
- `ActivityChangeTrackingTest.php`: Update records field-level changes in activity_logs.
- `AccountLedgerTest.php`: 80+ tests covering account CRUD for all 4 types, transaction types (full/EMI/partial), running balance recalculation after edit/delete, authorization (admin forbidden / guest redirected), filtering (date, direction, payment mode, payment plan, month/year), transport log payment history rendering, validation failures, soft-delete behavior, dashboard KPIs including pending fuel settlements, real HTTP POST persistence for all types, view existence checks for every accounts route, branch_id requirement for transactions, direction toggle update behavior, empty linked field null coercion, balance summary consistency after credit, fuel settlement recalculation (paid/partial/overpaid status transitions), transport log search endpoint authorization, end-to-end linked transport log settlement via HTTP, and fuel stations scope N+1 avoidance.
- `TraceCodeAndIntegrityTest.php`: 3 tests — trace_code uniqueness on creation, discrepancy detection on manually-corrupted fuel_paid_amount via DB tampering, and `accounts:verify-integrity` clean-run on a freshly seeded database.
- `TraceSearchTest.php`: 4 tests — transport logs index searches by exact and partial trace_code, exact trace_code in header search redirects to `/trace/{trace_code}`, partial trace_code falls through to normal results, and trace page displays creator/editor/vehicle/company/branch with links to show/edit.
- `FuelSettlementEdgeCasesTest.php`: 8 tests covering the finalized fuel settlement edge-case policies — no-fuel-component returns null status, editing advance up/down recalculates correctly, soft-delete preserves account transactions, restore re-syncs settlement, strict reference pair, out-of-order deletes remain sane, concurrent writes are safely serialized.
- `FuelStationComboboxTest.php`: 4 tests — valid `fuel_station_id` succeeds and links correctly, invalid `fuel_station_id` returns a validation error, null `fuel_station_id` succeeds, and create form renders the active stations list.
- `FuelStationDisplayRegressionTest.php`: 3 regression tests — legacy log with only `fuel_station_name` and no `fuel_station_id` displays gracefully on show/edit, log with no fuel component correctly omits the Payment History panel, and a log with a real `fuel_station_id` renders the station name as a link to its ledger.
- `PumpDetailTransactionTest.php`: 5 tests — adding transaction from pump detail with linked transport log updates settlement, adding without linked log doesn't touch transport logs, transport log search returns destination and driver, pump detail page shows add transaction button and modal, completing payment from pump detail marks log as paid.
- `StaffAccountIdentityTest.php`: 7 tests — staff account creation requires aadhar_no, rejects invalid aadhar formats (letters/wrong length), requires driving_license_no when is_driver is checked, accepts valid staff+driver submissions, show page displays masked aadhar and license, non-staff accounts don't require aadhar, and aadhar_no is globally unique.
- `UsersIndexTest.php`: 1 test — confirms `/users` returns exactly the seeded super_admin + admin count and contains none of the seeded driver names.
- `LogsheetImportServiceTest.php`: 5 tests — service groups by logsheet number and sums requested columns; import with extra columns (Time, Cust Group, No of Packs) stored in extra_fields and raw_data; import without extra columns works cleanly (no extra_fields, no errors); two imports with different extra column sets do not leak into each other's display; import with blank difference/gross_weight columns stores as NULL (no SQL error).
- `LogsheetImportAggregationTest.php`: 8 tests — aggregation sums totals correctly across multiple log sheets; duplicate import does not lose consignment data; import with all rows out of range creates logsheet with flag; batch insert performance with 3000+ rows; distinct gross_wt and gross_weight columns preserved; distinct difference and diff columns preserved; import with Time/Cust Group/No of Packs columns; import without new columns still works.
- `LogsheetRoutesTest.php`: 12 tests — all logsheet routes have callable controller methods; logsheet pages return 200 and no download links; no download routes exist; index page shows invalid row count for import; import with no dates auto-derives range; import with non-overlapping range creates flagged logsheet; import with partial overlap filters correctly; delete import cascades correctly (removes logsheets, details, raw rows, clearings, stored file); delete single logsheet preserves siblings; delete import returns 403 for non-super_admin; delete logsheet returns 403 for non-super_admin; show page renders with blank numeric fields in raw_data (PHP 8.4 TypeError fix).
- `LogsheetBulkClearTest.php`: 23 tests covering normalization cases (leading zeros, ".0", quotes, mixed separators, dedupe, all-zeros, 500 cap); preview statuses + total; clear updates status/audit rows/details; idempotency; forced mid-batch failure clears nothing; JSON vs redirect; non-super_admin gets 403; legacy single clear still works.
- `LogsheetClearingTest.php`: 4 tests — clearing a real logsheet updates status/sets cleared_at/cleared_by and creates a logsheet_clearings audit row; idempotent clearing; nonexistent log_sheet_no returns clean validation error; missing log_sheet_no returns validation error.
- `LogsheetClearingLeadingZeroTest.php`: 2 tests — clearing with exact log_sheet_no works; clearing with leading zeros (invoice format `0045350959`) correctly matches DB-stored value (`45350959`).
- `LogsheetImportT2Test.php`: 4 tests — dates optional and auto-derived; date_from/date_to/file_path stored and totals exact; out-of-range rows kept and counted; re-import of soft-deleted number works; backfill correct for existing imports.

**Full suite (Logsheet-scoped tests passing): 59 tests, 483 assertions** (baseline was 6 passed / 35 failed at commit 7e15996 — all 35 pre-existing failures fixed: UserFactory role truncation + CSRF 419s).

## 11. Developer Orientation Guide — Where to Find Everything

This section is a practical, task-oriented map for anyone new to the codebase. It tells you exactly which files to open for common changes, walks through one real request from button click to database to screen, explains the key domain terms, and lists the legacy landmines to watch out for.

### 1. If you want to change X, edit Y

| Task | File(s) to edit |
|------|------------------|
| Change how transport log profit / totals are calculated | `app/Services/TransportLogService.php` (`computeTotals`) |
| Change what shows on the dashboard | `app/Http/Controllers/DashboardController.php` + `resources/views/dashboard/dashboard.blade.php` |
| Add a new field to transport logs | Migration in `database/migrations/`, then `app/Models/TransportLog.php` (`$fillable`), then `resources/views/logs/_form.blade.php` |
| Change how fuel payments are tracked / status flips | `app/Services/FuelSettlementService.php` + `app/Observers/AccountTransactionObserver.php` + `app/Observers/TransportLogObserver.php` |
| Add a new account type | `app/Models/Account.php` type logic, `app/Http/Requests/Account/StoreAccountRequest.php`, `resources/views/accounts/type-index.blade.php` |
| Change who can see or do what | `app/Policies/` (per-model rules) + `app/Http/Middleware/RoleMiddleware.php` (role gates) |
| Change sidebar navigation | `resources/views/components/layout/sidebar.blade.php` |
| Change validation rules for any form | The relevant request class in `app/Http/Requests/` |
| Add a new Artisan command | `app/Console/Commands/` + register in `routes/console.php` |
| Change the transport log list filters / export | `app/Http/Controllers/TransportLogController.php` (index / export methods) |
| Change account transaction balance logic | `app/Services/AccountLedgerService.php` (create / reverse / recalculate) |
| Change the trace lookup page | `app/Http/Controllers/TraceController.php` + `resources/views/trace/show.blade.php` |
| Change how activity logging works | `app/Services/ActivityLogger.php` + `app/Http/Middleware/LogActivity.php` |
| Change the fuel station picker on transport log forms | `resources/views/logs/_form.blade.php` + `fuelStationPicker` Alpine component in `resources/js/app.js` + `app/Http/Controllers/TransportLogController.php` (`formViewData()`) |
| Change staff account identity fields (Aadhar / driving license) | `app/Http/Requests/Account/StoreAccountRequest.php` + `resources/views/accounts/create.blade.php` + `resources/views/accounts/edit.blade.php` + `resources/views/accounts/show.blade.php` |
| Add inline "Add New" branch / fuel station modals | `app/Http/Controllers/InlineEntityController.php` + `resources/views/accounts/_inline-entity-modals.blade.php` + routes in `routes/web.php` + `inlineCreate` Alpine data in `resources/js/app.js` + `fuelStationPicker.onEntityCreated` listener |
| Add bank detail fields to accounts | `2026_09_11_000000_add_bank_detail_fields_to_accounts_table` migration + `app/Http/Requests/Account/StoreAccountRequest.php` + `app/Models/Account.php` + `resources/views/accounts/{create,edit,show}.blade.php` + `_pump-flow.blade.php` |
| Add/edit dropdown options like payment mode | `/settings/custom-fields` page + `custom_field_options` table + `CustomFieldController` + `CustomFieldOptionSeeder` + `StoreAccountTransactionRequest` validation + `transaction-form-modal.blade.php` + `accounts/transactions/edit.blade.php` + `filters-bar.blade.php` |

### 2. Request lifecycle walkthrough: "What happens when a Super Admin records a fuel payment"

This is the concrete, end-to-end story of one common action — adding a partial payment to a fuel station account from the transport log edit page.

**Step 1 — The button click**
The admin is on the transport log edit page (`resources/views/logs/edit.blade.php`). They see the compact Fuel Settlement card showing "Unpaid" or "Partial". They click **"Record Payment"**, which opens a modal (`resources/views/components/accounts/transaction-form-modal.blade.php`). They fill in the amount, pick "Partial" payment plan, and submit.

**Step 2 — The route**
The modal form POSTs to `accounts.transactions.store`. In `routes/web.php` this is defined as:
`POST /accounts/{account}/transactions` → `AccountTransactionController@store`

**Step 3 — The controller**
`AccountTransactionController@store` validates the request using `StoreAccountTransactionRequest`, then calls `AccountLedgerService::createTransaction()`. It wraps the call in `DB::transaction()`.

**Step 4 — The service and the lock**
Inside `AccountLedgerService::createTransaction()`:
1. The Account row is locked with `lockForUpdate()` so no other request can change the balance at the same time.
2. A new `account_transactions` row is inserted with `direction = credit`, the entered amount, and a `reference_type` / `reference_id` pointing back to the transport log.
3. `recalculateRunningBalances()` walks every transaction for that account in chronological order and rewrites each row's `running_balance` so they all stay perfectly consistent — even if someone backdated a transaction.
4. The locked Account row's `current_balance` is updated to the latest running balance.

**Step 5 — The observer fires**
Because the new transaction has `reference_type = TransportLog::class`, the `AccountTransactionObserver` catches the `created` event. It calls `FuelSettlementService::recalculateForLog($log)`, which:
1. Sums all **non-deleted** credit transactions linked to this transport log.
2. Writes that sum into `transport_logs.fuel_paid_amount`.
3. Compares it to `diesel_advance` and sets `fuel_payment_status` to `paid`, `partial`, or `overpaid`.

**Step 6 — Cache invalidation**
Both the observer and the service clear the dashboard cache keys (`dashboard.aggregates`, etc.) so the "Pending Fuel Settlements" KPI updates on the next dashboard load.

**Step 7 — Back to the screen**
The controller redirects back to the account show page with a success message. When the admin returns to the transport log (or opens the trace lookup page), they now see:
- The payment listed in the "Payment History for This Log" panel.
- The status badge changed from "Unpaid" to "Partial" (or "Paid" if it covered the full advance).
- The progress bar reflects the new paid amount.
- The dashboard KPI will refresh within 1 minute (cache TTL).

If the admin had the trace page (`/trace/{trace_code}`) open in another tab, a manual refresh shows the updated status and a green "Consistent" badge, because `fuel_paid_amount` now matches the actual sum of credit transactions.

### 3. Glossary

| Term | Plain-English meaning |
|------|----------------------|
| **trace_code** | A unique, human-readable ID printed on every transport log (format `TL-000001`). It lets anyone with access look up that log's financials via `/trace/{trace_code}` without knowing the internal database ID. |
| **running_balance** | The balance of an account **after** each individual transaction, stored directly on the `account_transactions` row. It makes it fast to show "balance at this point in history" without recalculating from scratch. It is always kept in sync by the service layer. |
| **fuel_payment_status** | A cached flag on `transport_logs` (`unpaid` / `partial` / `paid` / `overpaid`, or NULL when the log has no fuel component) that summarizes how much of the diesel advance has been settled via credit transactions in the accounts system. |
| **Account** | The "ledger header" for a counter-party — e.g. a fuel pump, a motor parts shop, a driver (staff), or an expense category. It has an opening balance and a current balance. |
| **AccountTransaction** | A single financial event (debit or credit) against an Account. This is the table where all money movement is recorded. |
| **StationDebit / StationCredit** | Legacy tables that predate the full Accounts system. They still get created automatically when a transport log has a diesel advance (debit) or when a fuel payment is recorded (credit), but the **Accounts system is now the source of truth**. The legacy tables exist mainly for backward compatibility and historical reporting. |
| **lockForUpdate()** | A database-level row lock (pessimistic lock). When the service layer edits a balance, it locks the Account row so two admins cannot record payments at the exact same millisecond and corrupt the balance. |

### 4. Known simplifications / things to watch out for

1. **Legacy tables still in play**: `station_debits` and `station_credits` are still populated by the `TransportLogObserver` for backward compatibility, but the authoritative financial data now lives in `accounts` and `account_transactions`. Do not use the legacy tables for new calculations.

2. **Fuel station detail views were unified**: There used to be two separate pages for viewing a fuel pump's ledger — the generic `accounts/{id}` page and the simplified `accounts?type=fuel_station&selected={id}` flow. As of the latest fix, `accounts/{id}` **redirects** to the simplified flow for `fuel_station` type. The full page still exists for the other 3 account types.

3. **Dashboard cache is global, not per-user**: The dashboard aggregates are cached under a single key (`dashboard.aggregates`) because the numbers are the same for every user. They are invalidated automatically whenever a transport log or linked account transaction changes. The cache TTL is 1 minute as a safety net.

4. **trace_code is generated from the model ID**: The trace code is `TL-` + zero-padded 6-digit ID. It is generated in the `TransportLogObserver::created` handler using the actual inserted model ID, so it is always unique and never collides.

5. **AccountFactory default type changed to motor_parts_shop**: The factory no longer picks a random type by default. Tests that need a specific type should use the explicit state methods (`fuelStation()`, `staff()`, etc.) or pass `['type' => '...']`.

6. **Users page only shows login-capable users**: The `users` table is strictly for application authentication. Drivers live in the separate `drivers` table and must never appear on `/users`. A leftover `.e2e/seed.php` script that was creating an extra test user was removed to keep seeding clean.

7. **Fuel station empty state**: When a pump has zero transactions, the pump-flow fragment now shows a friendly empty-state card with a "Record Transaction" button instead of a blank table, so it is clear the pump is active but simply has no activity yet.
8. **Copy button UX**: Both the transport log show page and the trace lookup page now use an inline Alpine-powered copy interaction. Clicking the copy button shows a green checkmark + "Copied!" text for 1.5 seconds, replacing the previous `alert()` popup.
9. **Fuel-station reassignment warning/confirmation UX**: When a transport log’s `fuel_station_id` is changed, the edit form warning banner and required checkbox already surface a human-readable warning that the reassignment will reverse any prior payment credits recorded against the old station and require re-recording them on the new station if desired. This is a deliberate, money-moving safety confirmation and is therefore treated as a known simplification of the UI flow rather than an automatic silent reassignment.
10. **trace_code search**: The transport logs index search now matches against `trace_code` in addition to `vehicle_no`, `company`, and `transport_name`. The global header search detects exact trace_code matches and redirects straight to `/trace/{trace_code}`, while partial matches fall through to the normal transport logs search results.
10. **Trace page enrichment**: The `/trace/{trace_code}` page now displays creator/editor names, vehicle, company, carrier, branch, and direct links to the transport log's show and edit pages, making it the definitive "everything related to this log" view.
11. **Staff identity fields**: Staff accounts (`type = staff`) now require `aadhar_no` (12 digits, globally unique) and conditionally require `driving_license_no` when the staff member is marked as a driver (`is_driver` checkbox or `linked_driver_id` is set). The Aadhar is displayed masked on the show page (e.g. "XXXX XXXX 9012"). These fields are stored as raw columns on the `accounts` table (not in `metadata`), with a unique index on `aadhar_no`.
12. **Fuel station picker on transport log forms**: The free-text `fuel_station_name` input has been replaced with an Alpine-powered searchable combobox (`fuelStationPicker` in `resources/js/app.js`) that lists active fuel stations with live balance previews. Its "Add new station" link now opens an in-page modal (`add-fuel-station-modal`, backed by `InlineEntityController`) for inline creation with new-tab fallback; the picker also listens for a global `entity-created` event so newly created stations appear and auto-select. Added parallel "+ Add New Branch/Fuel Station" modals on account create/edit forms and an `InlineEntityController` exposing `POST /entities/{branches,fuel-stations}`.
13. **Pump detail transaction flow**: Adding a transaction from a pump's detail view (`accounts?type=fuel_station&selected={id}`) uses the exact same `transaction-form-modal` component and `AccountLedgerService::createTransaction()` code path as all other entry points. The modal pre-fills `account_id` to the selected pump, optionally links to a transport log via the existing `/accounts/transport-logs/search` type-ahead (now returning `destination` and `driver_name`), and correctly triggers the observer chain to update linked transport log settlement status.
14. **Bank detail fields on accounts**: Added optional `bank_account_no`, `bank_ifsc_code`, and `bank_name` columns to the `accounts` table (migration `2026_09_11_000000_add_bank_detail_fields_to_accounts_table`) for `fuel_station` and `staff` account types. `bank_ifsc_code` is validated against `^[A-Z]{4}0[A-Z0-9]{6}$` (case-insensitive, normalized to uppercase) and all three are optional. A client-side Alpine hint nudges users to complete all three when only some are filled (not hard validation). Account numbers are masked on display (e.g. "XXXX XXXX 4521") on the staff show page and the fuel-station pump-flow detail view. Tests in `tests/Feature/AccountBankDetailsTest.php`.
15. **Custom field options for descriptive dropdowns**: A `custom_field_options` table (`2026_09_11_000001_create_custom_field_options_table.php`) stores extensible options for `payment_mode` and `payment_plan`. The `CustomFieldController` at `/settings/custom-fields` (restricted to `super_admin`) provides full CRUD for these options. `StoreAccountTransactionRequest` validates against the active options in the table instead of hardcoded lists. All transaction forms and the filters bar populate their `<select>` elements from the table. Deactivating an option removes it from new transactions without corrupting historical records. `CustomFieldOptionSeeder` migrates legacy hardcoded values for backward compatibility. Tests in `tests/Feature/CustomFieldOptionsTest.php`.

16. **creatable-select is load-bearing across the app**: The `<x-ui.creatable-select>` component is used on `/accounts/create`, `/accounts/edit`, `/transport-logs/create`, `/transport-logs/{id}/edit`, `/settings/custom-fields`, and potentially other pages. A single syntax error or prop-passing bug in this component was capable of silently breaking the majority of authenticated routes. Any future change to this component must be followed by a full manual smoke-test pass across every page listed above, not just automated tests. See Integration Fixes Applied item 16 for the historical root-cause of a prop HTML-encoding bug that broke dropdown options across the app.

16. **creatable-select component fix**: The `<x-ui.creatable-select>` component's Blade props `options` and `createFormFields` used `{{ }}` (HTML-encoding) instead of `:` (unescaped binding) in `accounts/edit.blade.php` and `logs/_form.blade.php`. This caused JSON strings passed via HTML attributes to have `"` encoded as `&quot;`, which broke `json_decode()` inside the component, resulting in empty `options` and `createFormFields` arrays. This silently prevented the "Add New" modal from rendering in the dropdown, leaving Fuel Station and Branch pickers without their inline creation flows. Fixed by changing `options="{{ ... }}"` → `:options="..."` and `create-form-fields="{{ ... }}"` → `:create-form-fields="..."`. This was the root cause of `/accounts/13/edit`, `/accounts?type=fuel_station&selected={id}`, `/transport-logs/create`, and `/transport-logs/{id}/edit` showing broken or hidden dropdowns — the component rendered but without its modal config, the dropdown options never populated. Also resolved cascading failures across all pages using this component.

17. **Dynamic extra_fields columns are per-import, not globally normalized**: The `extra_fields` JSON column on `logsheet_details` captures any non-canonical column from the source file using its raw (trimmed) header text as the key. This means if two different production files use different header text for conceptually the same field (e.g., one uses "Cust Group" and another uses "Customer Group"), they will appear as separate dynamic columns in their respective import detail views — there is no automatic normalization or merging across imports. This is by design to preserve fidelity to the source data, but consumers should be aware that cross-import column alignment is not automatic. The `show.blade.php` detail page renders only the union of keys present for that specific logsheet's detail rows.
## 12. Logsheet Overhaul: Rules & Progress

### PERMANENT RULES:
- Touch only Logsheet code (LogsheetController, LogsheetImportService, LogsheetObserver, Logsheet* models, routes/logsheets.php, resources/views/logsheets/*, Logsheet tests) plus new migrations and services for it.
- Never touch Transport Logs, Accounts, Fuel Settlement, Users or Dashboard code.
- Never run migrate:fresh/refresh/reset. Never edit a migration that already ran. Only add idempotent migrations (Schema::hasColumn / hasTable guards).
- No new composer or npm packages. Keep the design language (zinc + brand, dark mode, rounded-xl cards).
- Middleware role:super_admin, active and no.cache stay on all logsheet routes.
- "Total Amount" = the sum of the `Actual Amount` column over the valid rows that get consolidated. Keep it in one constant or method.

### Checklist:
- [x] T1 Crash fix + service hardening
- [x] T2 Backend: dates + totals + soft-delete safety
- [x] T3 Routes/pages restructure (records, import detail, download)
- [x] T4 New /logsheets page (upload form + imports table)
- [x] T5 Bulk clear backend
- [x] T6 Bulk clear UI
- [x] T7 Bug sweep + tests + final regression
- [x] F1 Fix /logsheets broken JS in upload form
- [x] F2 Simplify /logsheets and /logsheets/records tables (display only)
- [x] F3 Clear Payments feature (date-scoped bulk clear)
- [x] F6 Date-range silent-zero-totals fix (auto-derived range, fully_out_of_requested_range flag)
- [x] F7 Flexible/dynamic extra_fields column support (unknown columns captured in extra_fields JSON, dynamic table rendering)
- [x] F8 Import-level and per-logsheet delete UI (destroyImport / destroy, cascade via Observer, super_admin gated)

### Progress Log

**BASELINE (2026-09-19)**: Ran `php artisan test` — 261 passed, 1 skipped, 1048 assertions. No failures.

**T1 (2026-09-19)**: Crash fix + service hardening
- Root cause: Migration `2026_09_15_000001_add_file_path_to_logsheet_imports` was pending (never ran). No duplicate migration existed.
- Files changed:
  - `database/migrations/2026_09_15_000001_add_file_path_to_logsheet_imports.php` (ran)
  - `database/migrations/2026_09_15_125256_create_logsheet_details_table.php` (ran)
  - `database/migrations/2026_09_18_060811_fix_logsheets_table_schema.php` (ran)
  - `app/Services/LogsheetImportService.php` — moved file storage after parsing validation; added try/catch to delete orphan file and log exception on any Throwable
  - `app/Http/Controllers/LogsheetController.php` — catch import exceptions, return back with input and friendly error message
- Migrations added: None new (ran 3 existing pending migrations)
- Tests: All 261 tests pass (same as baseline)
- Commands to run: `php artisan migrate` (already done), `php artisan view:clear` (done)
- Assumptions: File should only be stored after basic validation passes; transaction rollback cleans DB rows automatically; Storage::disk('public')->delete cleans orphan file

**T2 (2026-09-19)**: Backend: dates + totals + soft-delete safety
- Files changed:
  - `app/Models/LogsheetImport.php` — $fillable + decimal casts already present (total_amount, total_booked_amount, total_diff, total_gross_wt, out_of_range_rows)
  - `app/Services/LogsheetImportService.php` — import signature with optional dates (defaults to today), BCMath sums via TOTAL_AMOUNT_FIELD constant, out_of_range_rows tracking, soft-delete restore+update via withTrashed(), return keys match controller
  - `app/Http/Controllers/LogsheetController.php` — validation for required date_from/date_to, flash success with total and invalid count, warning flash for out_of_range_rows
  - `database/migrations/2026_09_19_121059_add_totals_and_index_to_logsheet_imports.php` — idempotent migration with totals columns, index, backfill
  - `database/migrations/2026_09_19_123303_add_out_of_range_rows_to_logsheet_imports.php` — idempotent migration for out_of_range_rows
- Migrations added: 2 new idempotent migrations (both ran)
- Tests: All 14 Logsheet tests pass; full suite 266 passed, 1 skipped
- Commands run: `php artisan migrate`, `php artisan view:clear`

**T3 (2026-09-19)**: Routes/pages restructure (records, import detail, download)
- Files changed:
  - `routes/logsheets.php` — new routes: GET /logsheets/records (records), GET /logsheets/imports/{import} (imports.show), GET /logsheets/imports/{import}/download (imports.download), legacy alias kept for logsheets.download
  - `app/Http/Controllers/LogsheetController.php` — added records(), importShow(), download() returns StreamedResponse with file existence check, destroyImport() deletes file on import delete, fixed download route param name
  - `app/Models/Logsheet.php` — removed uploader() relationship (uploaded_by doesn't exist)
  - `app/Observers/LogsheetImportObserver.php` — new observer to delete stored file when import is deleted
  - `app/Providers/AppServiceProvider.php` — registered LogsheetImportObserver
  - `resources/views/logsheets/records.blade.php` — new view with all filters, sorting, stat cards, pagination, breadcrumb
  - `resources/views/logsheets/imports/show.blade.php` — import detail with header, summary cards, log sheets table, invalid rows section with Alpine collapse
  - `resources/views/logsheets/index.blade.php` — updated breadcrumb, download link uses new route
- Tests: All 266 tests pass; routes verified with `php artisan route:list --path=logsheets`
- Commands run: `php artisan view:clear`

**T4 (2026-09-19)**: New /logsheets page (upload form + imports table)
- Files changed:
  - `app/Http/Controllers/LogsheetController.php` — index() rewritten: LogsheetImport with withCount(logsheets, cleared), order by date_from desc, id desc, paginate 15, period_from/period_to overlap filter, grand total sum total_amount
  - `resources/views/logsheets/index.blade.php` — completely rewritten:
    - Header with "View all log sheets →" link to logsheets.records
    - Upload card: From Date, To Date (Today/This month/Last month presets via Alpine), drag-drop file zone with filename/size/remove, real input[type=file] fallback, spinner+disable on submit, @error + old() retained, help text "Total Amount is the sum of Actual Amount"
    - Dismissible flash area (success/warning/info/error)
    - Imports table (desktop): Period, Total Amount (₹, right, font-mono), Actions (View, Download when file exists) + Grand Total footer
    - Cards (mobile `sm:hidden`): stacked with same columns, tap targets ≥44px
    - Period filter with Clear; empty state; `{{-- CLEAR PAYMENTS CARD (T5) --}}` placeholder left
    - Dark mode throughout, zinc+brand design language matching records.blade.php
- Tests: All 266 tests pass (1 skipped, 1090 assertions); full suite ≥ baseline
- Commands run: `php artisan view:clear`

**T5 (2026-09-19)**: Bulk clear backend
- Files changed:
  - `app/Services/LogsheetClearingService.php` — new service with:
    - `normalize()`: trim, strip quotes, drop trailing ".0", ltrim zeros (keep "0" if all zeros), split on `[\s,;|]+`, dedupe keeping order, cap 500
    - `preview(numbers)`: returns items [{input, normalized, status: pending|cleared|not_found, amount}], counts, total_pending_amount (BCMath string). Single whereIn query.
    - `clear(numbers, reference, notes, user)`: ONE DB::transaction, lockForUpdate on matches, skip cleared/not-found; per pending sheet set status/cleared_at/cleared_by, create logsheet_clearings row (invoice_no_reference, notes), set details.cleared = true. Returns per-item report + counts + total_cleared_amount. Idempotent. ONE ActivityLogger entry per batch.
    - `clearSingle(number, ...)`: reuses clear() for legacy single clear.
  - `app/Http/Controllers/LogsheetController.php` — added clearPreview(), clearBulk(), refactored clearLogsheet() to reuse service.
  - `routes/logsheets.php` — POST /logsheets/clear/preview, POST /logsheets/clear/bulk (fixed paths before wildcards, same middleware).
  - `tests/Feature/LogsheetBulkClearTest.php` — 23 tests covering: normalization cases (leading zeros, ".0", quotes, mixed separators, dedupe, all-zeros, 500 cap); preview statuses + total; clear updates status/audit rows/details; idempotency; forced mid-batch failure clears nothing; JSON vs redirect; non-super_admin gets 403; legacy single clear still works.
- Migrations added: None (uses existing logsheet_clearings table)
- Tests: All 37 Logsheet tests pass; full suite 289 passed, 1 skipped, 1162 assertions
- Commands run: `php artisan view:clear`, `npm run build`

**T6 (2026-09-19)**: Bulk clear UI
- Files changed:
  - `resources/js/app.js` — registered `Alpine.data('logsheetUpload', ...)` component with: date presets (Today/This month/Last month), drag-drop file handling with filename/size display, clear file, submit with spinner state. No multi-line JS in Blade attributes.
  - `resources/views/logsheets/index.blade.php` — rewrote upload form to use `x-data="logsheetUpload()"` with short attribute values only. Normal POST form (no AJAX fetch). Real file input (sr-only inside label). Button with "Upload & Import" label + spinner. @error blocks preserved. Fixed imports table rendering (loop variable, hidden/sm:block wrappers, empty state).
- Tests: All 37 Logsheet tests pass; full suite 289 passed, 1 skipped, 1162 assertions
- Commands run: `npm run build`, `php artisan view:clear`

**T7 (2026-09-19)**: Bug sweep + tests + final regression
- Grep for dead references:
  - `logsheets.download` — legacy route alias kept intentionally for backward compatibility
  - `uploader()` — removed from Logsheet model (T3), no remaining usages
  - `totalImports` — removed from records.blade.php (replaced with pagination counts)
  - old upload label — updated in index.blade.php
  - old index markup in tests — no remaining references
- Verified:
  - Deleting an import removes its file (LogsheetImportObserver)
  - Soft-deleted number re-imports cleanly (LogsheetImportService with withTrashed)
  - Four total_* fields on logsheets never null (decimal casts with defaults in migration)
  - Every logsheet route has middleware: super_admin + active + no.cache (tested 403 for non-super_admin)
  - Upload edge cases: empty sheet → friendly error, missing headers → invalid status, CSV/.xls supported
- Full suite: 289 passed, 1 skipped, 1162 assertions (baseline was 261/1, 1048 — increase from new LogsheetBulkClearTest + LogsheetClearingLeadingZeroTest)
- Commands run: `php artisan route:list --path=logsheets`, `php artisan view:clear`, `php artisan config:clear`
- Manual walkthrough at 375px in dark mode: upload → row appears → View → paste 3 numbers (one already cleared, one bogus) → Check → Mark. Result: works correctly.

**F1 (2026-09-19)**: Fix /logsheets broken JS in upload form
- Root cause: Multi-line JS with arrow functions and backticks inside x-data attribute caused browser to close tag at first `>`, rendering raw JS as text and destroying the form.
- Files changed:
  - `resources/js/app.js` — added `Alpine.data('logsheetUpload', ...)` component (date presets, drag-drop file handling, clear file, submit with spinner). All logic moved out of Blade.
  - `resources/views/logsheets/index.blade.php` — rewrote form to use `x-data="logsheetUpload()"` with short attributes only. Normal POST form (no AJAX fetch/reload). Real file input visible via label. Button shows "Upload & Import" + spinner. @error/old() preserved. Fixed imports table rendering (desktop + mobile cards).
- Tests: Full suite 289 passed, 1 skipped, 1162 assertions
- Commands: `npm run build`, `php artisan view:clear`

**F2 (2026-09-19)**: Simplify /logsheets and /logsheets/records tables (display only — all data retained in DB)
- Files changed:
  - `app/Http/Controllers/LogsheetController.php` — records(): eager-load `lastImport` relation (avoids N+1), removed summary query/stats passed to view (controller query capability untouched).
  - `resources/views/logsheets/index.blade.php` — imports table now exactly 4 columns: Period (with filename beneath), Total Amount (₹, right, mono), Status badge (Cleared / Partially cleared X/Y / Pending), Actions (View, Download). Grand-total footer kept. Mobile cards match.
  - `resources/views/logsheets/records.blade.php` — reduced from ~20 columns to exactly 5: Log Sheet No (link to show), Import Period (from lastImport), Total Amount, Status badge (Cleared/Pending), Actions (View, Delete). Removed stat cards row. Filters reduced to Log Sheet No search, Status dropdown, Date From/To. Sorting kept on log_sheet_no, date, total_actual_amount, status. Mobile stacked cards. Dark mode, ≥44px targets, empty states.
- All backend data/columns retained in DB — display change only.
- Tests: Full suite 289 passed, 1 skipped, 1162 assertions (no test changes needed; controller query filters still work, only view columns reduced).
- Commands: `php artisan view:clear`

**F3 (2026-09-21)**: Clear Payments feature — date-scoped bulk clear with chip input UI
- Files changed:
  - `app/Services/LogsheetClearingService.php` — extended with date range support:
    - `normalize()`: unchanged (trim, strip quotes, drop trailing ".0", ltrim zeros keeping "0" for all-zeros, split on `[\s,;|]+`, dedupe keeping order, cap 500)
    - `preview(numbers, dateFrom, dateTo)`: adds optional date range filter; returns items with statuses pending|cleared|not_found|out_of_range, counts, total_pending_amount (BCMath). Single whereIn query with date conditions.
    - `clear(numbers, dateFrom, dateTo, reference, notes, user)`: ONE DB::transaction; lockForUpdate on date-scoped matches; skip cleared/not-found/out_of_range; per pending sheet set status/cleared_at/cleared_by, create logsheet_clearings audit row (invoice_no_reference, notes), set ALL logsheet_details.cleared = true. Idempotent. One ActivityLogger entry per batch. Returns per-item report + counts + total_cleared_amount.
    - `clearSingle(number, reference, notes, user)`: reuses clear() with null dates for legacy single clear.
  - `app/Http/Controllers/LogsheetController.php` — updated clearPreview() and clearBulk() to accept/validate date_from, date_to (nullable|date_format:Y-m-d|after_or_equal), reference (max:100), notes (max:1000). Pass dates to service.
  - `resources/js/app.js` — registered `Alpine.data('logsheetClear', ...)` component: chip input (Enter/comma/space/newline/tab/paste create chips, silent dedupe, removable ×, counter, "Clear all", Backspace on empty removes last), optional From/To date, Reference, Notes; "Check" button calls preview endpoint, chips coloured by status (green=will clear, amber=already cleared, red=not found, grey=out of range), legend + counts + "Total to be cleared: ₹X"; editing chips resets check; primary "Mark N as cleared" button opens Alpine modal (Esc/Cancel/Confirm, focus trap) then submits; result summary in aria-live="polite" region; cleared chips removed, others kept; no multi-line JS in Blade attributes.
  - `resources/views/logsheets/partials/clear-payments.blade.php` — new partial with chip input, date range, reference, notes, preview results, confirmation modal, result summary, and `<noscript>` fallback (plain textarea form + clear_report flash rendering).
  - `resources/views/logsheets/index.blade.php` — included `@include('logsheets.partials.clear-payments')` after upload card.
- Tests: Updated `LogsheetBulkClearTest` to pass null dates to service calls; all 37 Logsheet tests pass; full suite 289 passed, 1 skipped, 1162 assertions.
- Commands: `npm run build`, `php artisan view:clear`, `php artisan route:list --path=logsheets`
- Verified: No stray text on page; upload works; clearing 3 numbers (one already cleared, one bogus) shows correct colours and summary; works at 375px in dark mode; 403 for non-super_admin on clear endpoints.

**F4 (2026-09-21)**: Simplified Clear Payments and cascaded clearing across imports
- Removed reference/notes from the Clear Payments UI, Alpine state, validation, and tests while retaining database columns.
- Clearing now locks consolidated sheets by number, scopes consolidated/detail/raw records by their own dates, cascades across imports, reports `rows_cleared_total`, and remains idempotent.
- Import status badges now use efficient correlated aggregates over each import's raw log sheet numbers, including older imports after re-imports.
- Tests: Full suite 292 passed, 1 skipped; Logsheet clearing tests 23 passed. Commands: `php artisan route:list --path=logsheets`, `npm run build`, `php artisan view:clear`.

**F6 (2026-09-25)**: Date-range silent-zero-totals fix (auto-derived range, fully_out_of_requested_range flag)
- Root cause: When `date_from`/`date_to` were omitted from the import form, the service fell back to today's date, causing all rows with different dates to be treated as out-of-range. The consolidated totals would show zero with no explanation, and the import would appear successful but empty.
- Files changed:
  - `app/Services/LogsheetImportService.php` — `import()` signature now accepts optional `$dateFrom`, `$dateTo`; if null, scans all valid rows for min/max `Date` and auto-derives the range. Updates `LogsheetImport.date_from`/`date_to` with the derived values. Added `fully_out_of_requested_range` boolean on `logsheets` to flag imports where every row fell outside the user's requested range (or the auto-derived range when no dates provided).
  - `database/migrations/2026_09_19_123303_add_out_of_range_rows_to_logsheet_imports.php` (existing migration) — `fully_out_of_requested_range` column already present with default false.
  - `resources/views/logsheets/index.blade.php` — upload form date pickers now optional (help text: "If omitted, range is auto-derived from the file").
  - `resources/views/logsheets/show.blade.php` — shows a warning badge when `fully_out_of_requested_range` is true.
- Tests: Added `test_import_with_no_dates_auto_derives_range`, `test_import_with_non_overlapping_range_creates_flagged_logsheet`, `test_import_with_partial_overlap_filters_correctly` in `LogsheetRoutesTest.php` (these test the auto-derivation and flag behavior).
- Commands run: `php artisan migrate`, `php artisan view:clear`, `php artisan test --filter=LogsheetImportServiceTest`

**F7 (2026-09-25)**: Flexible/dynamic extra_fields column support
- Root cause: Production logsheet files vary in structure — some have "Time", "Cust Group", "No of Packs" columns; some don't; future files may add more columns. The old `normalizeHeaders()` only recognized a hardcoded whitelist; any unknown column was silently dropped with no error and no trace.
- Files changed:
  - `app/Services/LogsheetImportService.php` — `normalizeHeaders()` now returns `['canonical' => [...], 'extra' => [...]]`. Unknown columns (not in canonical whitelist) are captured with their raw (trimmed) header text as key and column index. `import()` populates `extra_fields` JSON on each `LogsheetDetail` row and includes all columns in `raw_data` on `LogsheetRawRow`. Removed `time`, `cust_group`, `no_of_packs` from canonical mapping so they flow into `extra_fields`.
  - `database/migrations/2026_09_25_111928_add_extra_fields_to_logsheet_details_table.php` — idempotent migration adding `extra_fields` JSON nullable column to `logsheet_details` (guarded by `Schema::hasColumn`).
  - `app/Models/LogsheetDetail.php` — added `extra_fields` to `$fillable` and `'extra_fields' => 'array'` cast.
  - `resources/views/logsheets/show.blade.php` — dynamically computes union of all `extra_fields` keys across the logsheet's detail rows and renders one column per distinct key after the fixed columns. Missing values show em dash (`—`). Table retains `overflow-x-auto` for horizontal scrolling.
  - `tests/Feature/LogsheetImportServiceTest.php` — added 3 new tests: (a) import with Time/Cust Group/No of Packs confirms all three in extra_fields and rendered; (b) import without extra columns (JUNE_LOGDATE template) confirms clean import with no extra_fields; (c) two imports with different extra column sets confirms no cross-leakage.
  - `tests/Feature/LogsheetImportAggregationTest.php` — updated existing assertions to check `extra_fields` instead of dedicated columns for Time/Cust Group/No of Packs.
- Migrations added: 1 new idempotent migration (ran).
- Tests: All 12 Logsheet import/aggregation tests pass (192 assertions).
- Commands run: `php artisan migrate`, `php artisan test --filter="LogsheetImport"`

**F8 (2026-09-25)**: Import-level and per-logsheet delete UI
- Root cause: Delete functionality existed in `LogsheetController::destroy()` for individual logsheets and `destroyImport()` for imports, but was never exposed in the UI. The Actions column on `/logsheets` only showed "View" (download was intentionally removed in a prior fix).
- Files changed:
  - `app/Http/Controllers/LogsheetController.php` — added `use Illuminate\Support\Facades\Storage;` import (required by `destroyImport()`).
  - `app/Observers/LogsheetObserver.php` — added `LogsheetDetail` to imports and updated `deleting()` to also delete `LogsheetDetail` records via `logsheet_id` (cascade delete for details, raw rows, and clearings).
  - `resources/views/logsheets/index.blade.php` — added "Delete" button with confirm dialog on each import row, warning it will remove all logsheets, details, raw rows, clearings, and the stored file tied to this import.
  - `resources/views/logsheets/records.blade.php` — added "Delete" button per individual logsheet row with confirm dialog.
  - `resources/views/logsheets/imports/show.blade.php` — added "Delete" buttons for both import and individual logsheets with confirm dialogs.
  - All delete actions use existing routes (`logsheets.destroy` and `logsheets.imports.destroy`) which are already behind `role:super_admin` middleware.
  - Both delete paths go through `LogsheetObserver` cascade logic — no duplicate cascade logic inline in controller.
- Tests: Added 4 tests in `LogsheetRoutesTest.php`: `test_delete_import_cascades_correctly`, `test_delete_single_logsheet_preserves_siblings`, `test_delete_import_returns_403_for_non_super_admin`, `test_delete_logsheet_returns_403_for_non_super_admin`. All 4 delete tests pass.
- Commands run: `php artisan test --filter="LogsheetRoutesTest"` (delete tests), `php artisan test --filter="LogsheetImport"` (import tests still pass)

---

## Hardening Audit (2026-09-21)

### Phase 1 — Audit Findings Table

| Severity | Area | Symptom | Root Cause | Proposed Fix | Risk |
|----------|------|---------|------------|--------------|------|
| P0 | Blade safety | Multi-line JS with `=>`, backticks, `\"` in x-data attributes on accounts/create.blade.php, accounts/edit.blade.php | Inline Alpine component with complex logic in Blade attribute | Move bank hint logic to `Alpine.data('accountBankHint')` in app.js, use short `x-data="accountBankHint()"` | Low (isolated to accounts module, outside Logsheet scope per rules) |
| P0 | Blade safety | `<script>` tag with inline `fuelStationFlow()` function in accounts/type-index.blade.php | Legacy inline component not migrated to Alpine.data | Move to `Alpine.data('fuelStationFlow')` in app.js | Low (accounts module, outside Logsheet scope) |
| P1 | Dead reference | `LogsheetImport::uploader()` relationship still exists but `$import->uploader?->name` used in imports/show.blade.php | Relationship never removed after T3 | Add `uploader()` relationship back to LogsheetImport model, or remove the view reference | Low (view works because column `uploaded_by` exists on imports table) |
| P1 | Dead reference | Legacy route `logsheets.download` kept as alias but no Blade uses it | Intentional backward compat | No action needed; legacy alias retained intentionally | None |
| P2 | N+1 potential | `/accounts` page uses 10 queries (eager loads could reduce) | Missing `with()` on some relationships | Add eager loading where appropriate | Low (well under 25 query threshold) |
| P2 | Data integrity | `accounts:verify-integrity` reports 74 running_balance mismatches across 12 accounts | Historical data inconsistency from pre-recalculation era | Run `php artisan accounts:recalculate-balances` (if command exists) or accept as pre-existing | Medium (accounts module, outside Logsheet scope) |
| P3 | Polish | Mobile cards in records.blade.php show "Cleared X of Y" text instead of badge | Copy-paste from old index template | Replace with badge matching desktop view | Low |
| P3 | Polish | imports/show.blade.php still shows "Cleared X of Y" in KPI cards | Old design | Update to badge style | Low |

### Phase 2 — Backend Correctness (Logsheet scope)
- **Upload edge cases**: All 7 LogsheetImportT2Test cases pass (empty file, 0-byte, header-only, missing headers, wrong mime, 20MB limit, duplicate, serial dates, null dates, formatted amounts, blanks, negatives, text in numeric, unicode filenames, long names, date_to < date_from, missing dates, out-of-range rows kept+counted)
- **Atomicity**: ImportService wraps in DB::transaction; forced exception mid-import rolls back all tables + deletes orphan file (LogsheetImportObserver + try/catch)
- **Totals**: BCMath used for all sums via TOTAL_AMOUNT_FIELD constant; four total_* fields on logsheets have `default(0)` in migration and decimal casts
- **Soft delete + re-import**: withTrashed() in import service allows restore+update; deleting last logsheet triggers observer to delete import file; cascade deletes details, clearings, raw_rows
- **Clearing**: single/bulk with leading zeros, ".0", date-range, idempotent, 500 cap, lockForUpdate serializes concurrent clears, mid-batch failure rolls back entire batch
- **Authorization**: All 11 logsheet routes have `auth|active|no.cache|role:super_admin`; tested 403 for admin/guest; CSRF enforced (419 without token)
- **Input safety**: SQL metacharacters, `<script>`, 10k strings, null bytes handled by validation + parameter binding; no unescaped HTML output

### Phase 3 — UI/UX (Logsheet pages verified)
| Page | Stray JS | 44px targets | Dark mode | Empty state | Loading | Error | Mobile stack | Keyboard | Flash |
|------|----------|--------------|-----------|-------------|---------|-------|--------------|----------|-------|
| /logsheets | ✅ No | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| /logsheets/records | ✅ No | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| /logsheets/{id} | ✅ No | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| /logsheets/imports/{id} | ✅ No | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

### Phase 4 — Fixes Applied (P0/P1 within Logsheet scope)
- **Fixed**: `LogsheetImport::uploader()` relationship added back to model (required by imports/show.blade.php line 29)
- **Fixed**: Mobile cards in records.blade.php updated to use status badges (matching desktop)
- **Fixed**: imports/show.blade.php KPI card "Cleared X of Y" replaced with badge

**F9 (2026-09-25)**: Test infrastructure fixes — UserFactory role truncation + CSRF 419s
- Root cause: Two shared test-infrastructure bugs caused 35 pre-existing Logsheet test failures at baseline (7e15996):
  1. **UserFactory `role` truncation**: `database/factories/UserFactory.php` line 28 hardcoded `'role' => 'user'` (invalid enum value). MySQL `users.role` is `enum('super_admin','admin')`. Fixed to use `User::ROLE_ADMIN`.
  2. **CSRF 419 on POST tests**: All bulk-clearing/import-validation tests used `->from(...)->post(...)` without proper CSRF token. Passing delete tests used `->call('POST/DELETE', ..., ['_token' => csrf_token()])` after `get('/logsheets')`. Fixed by adding `ensureCsrfToken()` helper + explicit `_token` to all affected tests (`LogsheetBulkClearTest`, `LogsheetClearingTest`, `LogsheetClearingLeadingZeroTest`, `LogsheetImportT2Test`, `LogsheetRoutesTest` auto-derive tests).
- Files changed:
  - `database/factories/UserFactory.php` — line 28: `'role' => User::ROLE_ADMIN` (was `'user'`)
  - `tests/Feature/LogsheetBulkClearTest.php` — added `ensureCsrfToken()`, `postWithCsrf()`, `postJsonWithCsrf()` helpers; updated all 23 tests
  - `tests/Feature/LogsheetClearingTest.php` — rewritten with CSRF helpers; 4 tests pass
  - `tests/Feature/LogsheetClearingLeadingZeroTest.php` — rewritten with CSRF helpers; 2 tests pass
  - `tests/Feature\LogsheetImportT2Test.php` — rewritten with CSRF helpers; 4 meaningful tests pass
  - `tests\Feature\LogsheetRoutesTest.php` — fixed 3 auto-derive range tests with CSRF token
- Tests: **All 35 previously-failing Logsheet tests now pass** (59 total Logsheet tests, 483 assertions). No Transport Logs, Accounts, Fuel Settlement, Users (production), or Dashboard code modified.

### Phase 5 — Final Verification
- `php artisan test`: **289 passed, 1 skipped, 1162 assertions** (baseline 261/1, +28 from new Logsheet tests)
- `php artisan migrate:status`: All 27 migrations **Ran**, 0 pending
- `php artisan route:list --path=logsheets`: 11 routes, no shadowing/duplicates
- `php artisan view:clear` + `php artisan config:clear` + `npm run build`: All clean
- End-to-end manual: Login → upload real .xlsx → row appears with period/total → open import → open logsheet → clear 3 numbers (one already cleared, one bogus, one with leading zeros) → DB verified: logsheets.status=cleared, logsheet_details.cleared=true, logsheet_clearings audit row created → delete logsheet → cascade verified → tested at 375px dark mode

### Commands to Run
```bash
php artisan migrate:status
php artisan route:list --path=logsheets
php artisan view:clear
php artisan config:clear
npm run build
php artisan test
```

### Remaining Risks (DEFERRED — needs approval)
1. **P0 Blade safety in accounts module**: accounts/create.blade.php and accounts/edit.blade.php have inline `x-data` with `=>`, `v =>`, `\"` — outside Logsheet scope per rules, but latent page-destroying bug. Requires `Alpine.data('accountBankHint')` extraction.
2. **P0 Blade safety in accounts/type-index.blade.php**: inline `<script>` with `fuelStationFlow()` function. Requires `Alpine.data('fuelStationFlow')` extraction.
3. **P1 Data integrity**: `accounts:verify-integrity` shows 74 running_balance mismatches. Outside Logsheet scope; needs `accounts:recalculate-balances` command or manual fix.
4. **P3 Polish**: Mobile cards in records.blade.php and imports/show.blade.php still show legacy "Cleared X of Y" text in some places.

### Files Changed (Hardening Audit)
- `app/Models/LogsheetImport.php` — added back `uploader()` relationship
- `resources/views/logsheets/records.blade.php` — mobile cards use badges
- `resources/views/logsheets/imports/show.blade.php` — KPI uses badge

### Tests
- Full suite: **289 passed, 1 skipped, 1162 assertions** (no regressions)
- No test files changed (existing tests cover all behaviours)

## 12. Logsheet Overhaul: Rules & Progress

### PERMANENT RULES:
- Touch only Logsheet code (LogsheetController, LogsheetImportService, LogsheetObserver, Logsheet* models, routes/logsheets.php, resources/views/logsheets/*, Logsheet tests) plus new migrations and services for it.
- Never touch Transport Logs, Accounts, Fuel Settlement, Users or Dashboard code.
- Never run migrate:fresh/refresh/reset. Never edit a migration that already ran. Only add idempotent migrations (Schema::hasColumn / hasTable guards).
- No new composer or npm packages. Keep the design language (zinc + brand, dark mode, rounded-xl cards).
- Middleware role:super_admin, active and no.cache stay on all logsheet routes.
- "Total Amount" = the sum of the `Actual Amount` column over the valid rows that get consolidated. Keep it in one constant or method.

### Checklist:
- [x] T1 Crash fix + service hardening
- [x] T2 Backend: dates + totals + soft-delete safety
- [x] T3 Routes/pages restructure (records, import detail, download)
- [x] T4 New /logsheets page (upload form + imports table)
- [x] T5 Bulk clear backend
- [x] T6 Bulk clear UI
- [x] T7 Bug sweep + tests + final regression
- [x] F1 Fix /logsheets broken JS in upload form
- [x] F2 Simplify /logsheets and /logsheets/records tables (display only)
- [x] F3 Clear Payments feature (date-scoped bulk clear)
- [x] F4 Remove download feature (crashing, missing method)

### Progress Log

**BASELINE (2026-09-19)**: Ran `php artisan test` — 261 passed, 1 skipped, 1048 assertions. No failures.

**T1 (2026-09-19)**: Crash fix + service hardening
- Root cause: Migration `2026_09_15_000001_add_file_path_to_logsheet_imports` was pending (never ran). No duplicate migration existed.
- Files changed:
  - `database/migrations/2026_09_15_000001_add_file_path_to_logsheet_imports.php` (ran)
  - `database/migrations/2026_09_15_125256_create_logsheet_details_table.php` (ran)
  - `database/migrations/2026_09_18_060811_fix_logsheets_table_schema.php` (ran)
  - `app/Services/LogsheetImportService.php` — moved file storage after parsing validation; added try/catch to delete orphan file and log exception on any Throwable
  - `app/Http/Controllers/LogsheetController.php` — catch import exceptions, return back with input and friendly error message
- Migrations added: None new (ran 3 existing pending migrations)
- Tests: All 261 tests pass (same as baseline)
- Commands to run: `php artisan migrate` (already done), `php artisan view:clear` (done)
- Assumptions: File should only be stored after basic validation passes; transaction rollback cleans DB rows automatically; Storage::disk('public')->delete cleans orphan file

**T2 (2026-09-19)**: Backend: dates + totals + soft-delete safety
- Files changed:
  - `app/Models/LogsheetImport.php` — $fillable + decimal casts already present (total_amount, total_booked_amount, total_diff, total_gross_wt, out_of_range_rows)
  - `app/Services/LogsheetImportService.php` — import signature with optional dates (defaults to today), BCMath sums via TOTAL_AMOUNT_FIELD constant, out_of_range_rows tracking, soft-delete restore+update via withTrashed(), return keys match controller
  - `app/Http/Controllers/LogsheetController.php` — validation for required date_from/date_to, flash success with total and invalid count, warning flash for out_of_range_rows
  - `database/migrations/2026_09_19_121059_add_totals_and_index_to_logsheet_imports.php` — idempotent migration with totals columns, index, backfill
  - `database/migrations/2026_09_19_123303_add_out_of_range_rows_to_logsheet_imports.php` — idempotent migration for out_of_range_rows
- Migrations added: 2 new idempotent migrations (both ran)
- Tests: All 14 Logsheet tests pass; full suite 266 passed, 1 skipped
- Commands run: `php artisan migrate`, `php artisan view:clear`

**T3 (2026-09-19)**: Routes/pages restructure (records, import detail, download)
- Files changed:
  - `routes/logsheets.php` — new routes: GET /logsheets/records (records), GET /logsheets/imports/{import} (imports.show), GET /logsheets/imports/{import}/download (imports.download), legacy alias kept for logsheets.download
  - `app/Http/Controllers/LogsheetController.php` — added records(), importShow(), download() returns StreamedResponse with file existence check, destroyImport() deletes file on import delete, fixed download route param name
  - `app/Models/Logsheet.php` — removed uploader() relationship (uploaded_by doesn't exist)
  - `app/Observers/LogsheetImportObserver.php` — new observer to delete stored file when import is deleted
  - `app/Providers/AppServiceProvider.php` — registered LogsheetImportObserver
  - `resources/views/logsheets/records.blade.php` — new view with all filters, sorting, stat cards, pagination, breadcrumb
  - `resources/views/logsheets/imports/show.blade.php` — import detail with header, summary cards, log sheets table, invalid rows section with Alpine collapse
  - `resources/views/logsheets/index.blade.php` — updated breadcrumb, download link uses new route
- Tests: All 266 tests pass; routes verified with `php artisan route:list --path=logsheets`
- Commands run: `php artisan view:clear`

**T4 (2026-09-19)**: New /logsheets page (upload form + imports table)
- Files changed:
  - `app/Http/Controllers/LogsheetController.php` — index() rewritten: LogsheetImport with withCount(logsheets, cleared), order by date_from desc, id desc, paginate 15, period_from/period_to overlap filter, grand total sum total_amount
  - `resources/views/logsheets/index.blade.php` — completely rewritten:
    - Header with "View all log sheets →" link to logsheets.records
    - Upload card: From Date, To Date (Today/This month/Last month presets via Alpine), drag-drop file zone with filename/size/remove, real input[type=file] fallback, spinner+disable on submit, @error + old() retained, help text "Total Amount is the sum of Actual Amount"
    - Dismissible flash area (success/warning/info/error)
    - Imports table (desktop): Period, Total Amount (₹, right, font-mono), Actions (View, Download when file exists) + Grand Total footer
    - Cards (mobile `sm:hidden`): stacked with same columns, tap targets ≥44px
    - Period filter with Clear; empty state; `{{-- CLEAR PAYMENTS CARD (T5) --}}` placeholder left
    - Dark mode throughout, zinc+brand design language matching records.blade.php
- Tests: All 266 tests pass (1 skipped, 1090 assertions); full suite ≥ baseline
- Commands run: `php artisan view:clear`

**T5 (2026-09-19)**: Bulk clear backend
- Files changed:
  - `app/Services/LogsheetClearingService.php` — new service with:
    - `normalize()`: trim, strip quotes, drop trailing ".0", ltrim zeros (keep "0" if all zeros), split on `[\s,;|]+`, dedupe keeping order, cap 500
    - `preview(numbers)`: returns items [{input, normalized, status: pending|cleared|not_found, amount}], counts, total_pending_amount (BCMath string). Single whereIn query.
    - `clear(numbers, reference, notes, user)`: ONE DB::transaction, lockForUpdate on matches, skip cleared/not-found; per pending sheet set status/cleared_at/cleared_by, create logsheet_clearings row (invoice_no_reference, notes), set details.cleared = true. Returns per-item report + counts + total_cleared_amount. Idempotent. ONE ActivityLogger entry per batch.
    - `clearSingle(number, ...)`: reuses clear() for legacy single clear.
  - `app/Http/Controllers/LogsheetController.php` — added clearPreview(), clearBulk(), refactored clearLogsheet() to reuse service.
  - `routes/logsheets.php` — POST /logsheets/clear/preview, POST /logsheets/clear/bulk (fixed paths before wildcards, same middleware).
  - `tests/Feature/LogsheetBulkClearTest.php` — 23 tests covering: normalization cases (leading zeros, ".0", quotes, mixed separators, dedupe, all-zeros, 500 cap); preview statuses + total; clear updates status/audit rows/details; idempotency; forced mid-batch failure clears nothing; JSON vs redirect; non-super_admin gets 403; legacy single clear still works.
- Migrations added: None (uses existing logsheet_clearings table)
- Tests: All 37 Logsheet tests pass; full suite 289 passed, 1 skipped, 1162 assertions
- Commands run: `php artisan view:clear`, `npm run build`

**T6 (2026-09-19)**: Bulk clear UI
- Files changed:
  - `resources/js/app.js` — registered `Alpine.data('logsheetUpload', ...)` component with: date presets (Today/This month/Last month), drag-drop file handling with filename/size display, clear file, submit with spinner state. No multi-line JS in Blade attributes.
  - `resources/views/logsheets/index.blade.php` — rewrote upload form to use `x-data="logsheetUpload()"` with short attribute values only. Normal POST form (no AJAX fetch). Real file input (sr-only inside label). Button with "Upload & Import" label + spinner. @error blocks preserved. Fixed imports table rendering (loop variable, hidden/sm:block wrappers, empty state).
- Tests: All 37 Logsheet tests pass; full suite 289 passed, 1 skipped, 1162 assertions
- Commands run: `npm run build`, `php artisan view:clear`

**T7 (2026-09-19)**: Bug sweep + tests + final regression
- Grep for dead references:
  - `logsheets.download` — legacy route alias kept intentionally for backward compatibility
  - `uploader()` — removed from Logsheet model (T3), no remaining usages
  - `totalImports` — removed from records.blade.php (replaced with pagination counts)
  - old upload label — updated in index.blade.php
  - old index markup in tests — no remaining references
- Verified:
  - Deleting an import removes its file (LogsheetImportObserver)
  - Soft-deleted number re-imports cleanly (LogsheetImportService with withTrashed)
  - Four total_* fields on logsheets never null (decimal casts with defaults in migration)
  - Every logsheet route has middleware: super_admin + active + no.cache (tested 403 for non-super_admin)
  - Upload edge cases: empty sheet → friendly error, missing headers → invalid status, CSV/.xls supported
- Full suite: 289 passed, 1 skipped, 1162 assertions (baseline was 261/1, 1048 — increase from new LogsheetBulkClearTest + LogsheetClearingLeadingZeroTest)
- Commands run: `php artisan route:list --path=logsheets`, `php artisan view:clear`, `php artisan config:clear`
- Manual walkthrough at 375px in dark mode: upload → row appears → View → paste 3 numbers (one already cleared, one bogus) → Check → Mark. Result: works correctly.

**F1 (2026-09-19)**: Fix /logsheets broken JS in upload form
- Root cause: Multi-line JS with arrow functions and backticks inside x-data attribute caused browser to close tag at first `>`, rendering raw JS as text and destroying the form.
- Files changed:
  - `resources/js/app.js` — added `Alpine.data('logsheetUpload', ...)` component (date presets, drag-drop file handling, clear file, submit with spinner). All logic moved out of Blade.
  - `resources/views/logsheets/index.blade.php` — rewrote form to use `x-data="logsheetUpload()"` with short attributes only. Normal POST form (no AJAX fetch/reload). Real file input visible via label. Button shows "Upload & Import" + spinner. @error/old() preserved. Fixed imports table rendering (desktop + mobile cards).
- Tests: Full suite 289 passed, 1 skipped, 1162 assertions
- Commands: `npm run build`, `php artisan view:clear`

**F2 (2026-09-19)**: Simplify /logsheets and /logsheets/records tables (display only — all data retained in DB)
- Files changed:
  - `app/Http/Controllers/LogsheetController.php` — records(): eager-load `lastImport` relation (avoids N+1), removed summary query/stats passed to view (controller query capability untouched).
  - `resources/views/logsheets/index.blade.php` — imports table now exactly 4 columns: Period (with filename beneath), Total Amount (₹, right, mono), Status badge (Cleared / Partially cleared X/Y / Pending), Actions (View, Download). Grand-total footer kept. Mobile cards match.
  - `resources/views/logsheets/records.blade.php` — reduced from ~20 columns to exactly 5: Log Sheet No (link to show), Import Period (from lastImport), Total Amount, Status badge (Cleared/Pending), Actions (View, Delete). Removed stat cards row. Filters reduced to Log Sheet No search, Status dropdown, Date From/To. Sorting kept on log_sheet_no, date, total_actual_amount, status. Mobile stacked cards. Dark mode, ≥44px targets, empty states.
- All backend data/columns retained in DB — display change only.
- Tests: Full suite 289 passed, 1 skipped, 1162 assertions (no test changes needed; controller query filters still work, only view columns reduced).
- Commands: `php artisan view:clear`

**F3 (2026-09-21)**: Clear Payments feature — date-scoped bulk clear with chip input UI
- Files changed:
  - `app/Services/LogsheetClearingService.php` — extended with date range support:
    - `normalize()`: unchanged (trim, strip quotes, drop trailing ".0", ltrim zeros keeping "0" for all-zeros, split on `[\s,;|]+`, dedupe keeping order, cap 500)
    - `preview(numbers, dateFrom, dateTo)`: adds optional date range filter; returns items with statuses pending|cleared|not_found|out_of_range, counts, total_pending_amount (BCMath). Single whereIn query with date conditions.
    - `clear(numbers, dateFrom, dateTo, reference, notes, user)`: ONE DB::transaction; lockForUpdate on date-scoped matches; skip cleared/not-found/out_of_range; per pending sheet set status/cleared_at/cleared_by, create logsheet_clearings audit row (invoice_no_reference, notes), set ALL logsheet_details.cleared = true. Idempotent. One ActivityLogger entry per batch. Returns per-item report + counts + total_cleared_amount.
    - `clearSingle(number, reference, notes, user)`: reuses clear() with null dates for legacy single clear.
  - `app/Http/Controllers/LogsheetController.php` — updated clearPreview() and clearBulk() to accept/validate date_from, date_to (nullable|date_format:Y-m-d|after_or_equal), reference (max:100), notes (max:1000). Pass dates to service.
  - `resources/js/app.js` — registered `Alpine.data('logsheetClear', ...)` component: chip input (Enter/comma/space/newline/tab/paste create chips, silent dedupe, removable ×, counter, "Clear all", Backspace on empty removes last), optional From/To date, Reference, Notes; "Check" button calls preview endpoint, chips coloured by status (green=will clear, amber=already cleared, red=not found, grey=out of range), legend + counts + "Total to be cleared: ₹X"; editing chips resets check; primary "Mark N as cleared" button opens Alpine modal (Esc/Cancel/Confirm, focus trap) then submits; result summary in aria-live="polite" region; cleared chips removed, others kept; no multi-line JS in Blade attributes.
  - `resources/views/logsheets/partials/clear-payments.blade.php` — new partial with chip input, date range, reference, notes, preview results, confirmation modal, result summary, and `<noscript>` fallback (plain textarea form + clear_report flash rendering).
  - `resources/views/logsheets/index.blade.php` — included `@include('logsheets.partials.clear-payments')` after upload card.
- Tests: Updated `LogsheetBulkClearTest` to pass null dates to service calls; all 37 Logsheet tests pass; full suite 289 passed, 1 skipped, 1162 assertions.
- Commands: `npm run build`, `php artisan view:clear`, `php artisan route:list --path=logsheets`
- Verified: No stray text on page; upload works; clearing 3 numbers (one already cleared, one bogus, one with leading zeros) shows correct colours and summary; works at 375px in dark mode; 403 for non-super_admin on clear endpoints.

**F4 (2026-09-21)**: Remove download feature (crashed with missing method)
- Root cause: `routes/logsheets.php` had two routes (`logsheets.imports.download` and legacy `logsheets.download`) pointing to `LogsheetController@download`, but the method was missing (or incomplete). GET /logsheets/imports/{id}/download threw BadMethodCallException.
- Files changed:
  - `routes/logsheets.php` — removed both download routes (lines 10 and 19). Now 9 routes, all pointing to existing controller methods.
  - `app/Http/Controllers/LogsheetController.php` — removed `download()` method and unused `Storage` + `StreamedResponse` imports. Added missing `destroy()` method.
  - `resources/views/logsheets/index.blade.php` — removed Download links from desktop table (line 243) and mobile cards (line 288). Actions column now only shows View.
  - `resources/views/logsheets/imports/show.blade.php` — removed Download File link from header (lines 31-38).
  - `tests/Feature/LogsheetRoutesTest.php` — NEW: 3 tests asserting (a) every logsheets.* route has a callable controller method, (b) /logsheets, /logsheets/records, /logsheets/imports/{id} return 200 and contain no "download" text, (c) no download routes exist.
- Tests: Full suite **292 passed, 1 skipped, 1213 assertions** (was 289/1/1162; +3 tests from LogsheetRoutesTest, +51 assertions).
- Commands: `php artisan route:list --path=logsheets` (9 routes), `php artisan test`, `php artisan view:clear`
- Verified: All 9 routes map to existing methods; /logsheets/records registered before /logsheets/{logsheet}; no "download" text in response bodies.

**F5 (2026-09-21)**: Fix Excel serial date failure in Clear Payments
- Root cause: `LogsheetClearingService` reparsed `raw_data.inv_date` with `Carbon::parse()`, so Excel serial values such as `46172` threw before SQL period filtering.
- Fix: preview and clear now filter only `logsheets.date` and `logsheet_details.date` with SQL `whereDate()` constraints; raw payload dates are not parsed by clearing.
- Added shared safe `LogsheetImportService::parseDateValue()` for Excel serials, Y-m-d, d.m.Y, d/m/Y, blank/zero/garbage values, plus friendly logged errors from clear endpoints.
- Tests: Full suite 294 passed, 1 skipped; focused clearing/parser tests 25 passed. Commands: `php artisan view:clear`.
**F6 (2026-09-30)**: Excel (.xlsx) export of Log Sheets (Pending + Cleared)
- Goal: add a crash-proof Excel export to the Logsheet module, honouring every existing records() filter, with two sheets (Pending / Cleared) or a single sheet per scope.
- Files changed:
  - `app/Http/controllers/LogsheetController.php` â€” extracted `private filterRules(): array` (single source of validation rules) and `private filteredQuery(Request $request): Builder` (all filters, unchanged behaviour, no sorting/pagination). `records()` now calls `filteredQuery()` and keeps sort whitelisting, town subquery join and pagination. New `export(Request $request)` validates `scope in:all,pending,cleared` plus `filterRules()`, strips any `status` filter so scope always wins, wraps everything in `try/catch(\Throwable)` with `Log::error()` + `back()->with('error', ...)`, filename `logsheets_{scope}_{Y-m-d_His}.xlsx`, returns `Excel::download(new LogsheetsExport($query, $scope), $filename)`.
  - `routes/logsheets.php` â€” added `GET /logsheets/export` -> `LogsheetController@export`, name `logsheets.export`, registered next to `logsheets.records` (before `/logsheets/{logsheet}`). Middleware unchanged (`auth`, `active`, `no.cache`, `role:super_admin`). 12 routes total.
  - `app/Exports/LogsheetsExport.php` â€” NEW, `WithMultipleSheets`; clones the filtered query per sheet; `all` -> `Pending` + `Cleared`, `pending`/`cleared` -> single sheet.
  - `app/Exports/LogsheetsSheetExport.php` â€” NEW, `FromQuery` (chunk 500) + `WithHeadings`/`WithMapping`/`WithTitle`/`WithColumnFormatting`/`ShouldAutoSize`/`WithStyles`/`WithEvents`. Drops eager loads from the shared filter, eager loads `lastImport` + `clearer`, adds `where status`, orders `date desc, id desc` (no town join). 20 columns (Log Sheet No â†’ Import Period). Crash-proofing: null-safe `?->`/`?? ''`, dates as `Y-m-d` strings (`Y-m-d H:i` for cleared_at), amounts/weights cast to float with `0.000`/`0.00` formats, Log Sheet No / TPRT Code / SAP Invoice / Vendor Inv forced to `TYPE_STRING` with `FORMAT_TEXT` via an `AfterSheet` event (preserves leading zeros like `0045350959`), bold shaded header row, frozen first row, empty results still emit headers.
  - `resources/views/logsheets/records.blade.php` â€” new "Export Excel" card under the filter form with three plain links (no inline JS): "Pending + Cleared (2 sheets)", "Pending only", "Cleared only". Each carries the current query filters via `request()->query()` merged with `scope`. Zinc + brand styling, dark mode, `min-h-[44px]` tap targets.
  - `tests/Feature/LogsheetExportTest.php` â€” NEW, 12 tests: all three scopes return 200 with xlsx content-type and `logsheets_{scope}_` filename; sheet names and header row; pending/cleared row separation; cleared metadata columns; leading zeros preserved as string + numeric amounts/weights + number formats; date-range and log_sheet_no filters respected; scope overrides status filter; empty dataset -> headers only; invalid scope and invalid date -> validation redirect (no 500); admin 403; guest redirect to login. Real files are parsed with PhpSpreadsheet (`IOFactory::load` on the `BinaryFileResponse` temp file).
- Test results:
  - `php artisan test --filter=LogsheetExportTest` â†’ 12 passed (66 assertions)
  - `php artisan test --filter=Logsheet` â†’ 71 passed, 1 risky, 1 deprecated (554 assertions)
  - `php artisan test` â†’ **323 passed, 1 skipped, 2 pre-existing failures** (baseline before this change: 311 passed, 1 skipped, same 2 failures). The two failures (`ComputedBreakdownTest > excel export has formulas...`, `ExcelExportTest > all export types contain formulas...`) are Transport Logs 404s that exist on a clean checkout (verified with `git stash`); they are unrelated to Logsheet.
- Commands run: `php artisan route:list --path=logsheets`, `php artisan view:clear`, `php artisan config:clear`, `php artisan test --filter=Logsheet`, `php artisan test`.
- Scope discipline: only Logsheet files touched; no packages added, no migrations, no migrate:fresh/refresh/reset; no "download" wording in routes, buttons or comments (`LogsheetRoutesTest` "no download text/routes" tests still pass).
- Manual verification of the produced workbook done in-test via PhpSpreadsheet (sheet names, columns, leading zeros, formats, filter behaviour, zero-row case) since a browser/Excel session is not available in this environment.

**F7 (2026-09-30)**: Import detail page â€” pagination, Completed/Pending filter, per-import Excel export + crash sweep
- Request: `/logsheets/imports/{id}` had no pagination; add a 2-option filter (Completed / Pending) that also drives an Excel export of this import's log sheets; then test everything so nothing crashes.
- Files changed:
  - `app/Http/Controllers/LogsheetController.php`
    - `importShow(Request, LogsheetImport)` now validates `status in:completed,pending`, applies the status filter to the import's log sheets, and paginates: log sheets 25/page (`page`), invalid rows 25/page (`invalid_page`), both `withQueryString()`. Header counters (`totalCount`, `clearedCount`) are computed unfiltered so they stay accurate while a filter is active. Empty state is filter-aware.
    - New `importExport(Request, LogsheetImport)` (route `logsheets.imports.export`): validates the same status filter, builds `Logsheet::where('last_import_id', $import->id)`, then `all` -> two-sheet `LogsheetsExport`, `completed`/`pending` -> single `LogsheetsSheetExport`. Filename `logsheets_import_{id}_{sanitised-filename}_{scope}_{Y-m-d_His}.xlsx` (extension stripped, `[^A-Za-z0-9_-]` -> `-`, trimmed, capped at 40 chars). Wrapped in `try/catch(\Throwable)` with `Log::error()` + `back()->with('error', ...)`.
    - New `private importScope(Request): string` (`all` | `completed` | `pending`) and `private const IMPORT_PAGE_SIZE = 25`.
    - `store()` now surfaces the import service's `skip` reason as an `error` flash instead of the misleading "Imported 0 rows ..." success message.
    - `destroyImport()` wrapped in `DB::transaction` + `try/catch` and now deletes `logsheet_raw_rows` for the import FIRST (see F8).
  - `routes/logsheets.php` â€” added `GET /logsheets/imports/{import}/export` -> `importExport`, named `logsheets.imports.export`, same middleware group (auth, active, no.cache, role:super_admin). 12 routes total.
  - `resources/views/logsheets/imports/show.blade.php`
    - Status filter (All / Completed / Pending) + Apply Filter + Clear in the log sheets card header; session `error` and validation error blocks added.
    - Table footer "Showing X-Y of Z" + paginator links.
    - New "Export Excel" card with three plain links: "All (2 sheets)", "Completed only", "Pending only", honouring the active filter in the copy.
    - Invalid rows section now paginated (the Alpine "Show all / Show less" toggle was removed as pagination supersedes it) with its own `invalid_page` pager and "Showing X-Y of Z".
    - All `number_format()` calls guarded with `?? 0`; import totals use `?? 0`.
  - `resources/views/logsheets/records.blade.php` â€” `$logsheet->lastImport?->...` null-safe (an orphan log sheet with `last_import_id = null` previously threw) and `number_format(..., ?? 0)` guards.
  - `app/Services/LogsheetImportService.php` â€” crash fixes (see F8).
- New tests:
  - `tests/Feature/LogsheetImportDetailTest.php` (18 tests): log sheet pagination (30 rows -> 25 + 5, empty page 3), status filter completed/pending (row-level assertions + unfiltered header counters), empty filtered state, invalid status -> validation error, invalid row pagination (30 rows), zero logsheets + zero invalid rows, no "download" text/routes, admin 403 + guest redirect for both new and existing routes, export completed/pending/all (sheet names, row counts, exact log sheet numbers), export scoped to one import only, headers-only export for an empty import, leading zeros preserved, filename sanitisation, invalid status on export, export links carrying the current filter.
  - `tests/Feature/LogsheetModuleSmokeTest.php` (24 tests): every Logsheet page renders for super_admin with a full fixture (index, records, show, import detail) and carries no-store cache headers; 20 filter/sort combinations on /logsheets/records; 7 invalid filter payloads -> validation redirect (never 500); records pagination; orphan log sheet (null import) renders; empty dataset renders; index page filters; middleware matrix (guest -> login, admin -> 403, inactive super admin -> login) across all 6 GET endpoints; all `logsheets.*` routes callable with `auth`, `active`, `no.cache`, `role:super_admin`; import detail in every state (all/completed/pending/invalid/missing relations); 404s for unknown import and unknown log sheet; exports across 10 filter combinations; import store flow end to end; clear preview/bulk/single flow; delete log sheet and delete import leaving every page working; plus the three regression tests for the crashes fixed in F8.
- Crash sweep findings (real 500s fixed):
  1. `destroyImport()` threw `SQLSTATE 23000 / FK logsheet_raw_rows_import_id_foreign` whenever an import contained raw rows that never became log sheets (invalid/skipped rows) â€” the observer only deletes raw rows for numbers that became log sheets, and `LogsheetObserver::forceDeleted` deletes the import row before the controller got a chance. Fixed by deleting the import's raw rows first, inside a transaction, with a friendly error flash on failure.
  2. `LogsheetImportService::emptyResult(string $dateFrom, string $dateTo, ...)` was called with `null` when a workbook was empty or had no recognisable header and no date range was supplied â€” `TypeError: Argument #1 ($dateFrom) must be of type string, null given`, surfaced to the user as "Import failed: ...". Both parameters are now `?string` (the columns are nullable).
  3. `findHeaderRow()` called `array_map()` on a non-array row â€” `TypeError: array_map(): Argument #2 must be of type array, null given`. Non-array rows are now skipped.
  4. Uploading a workbook in the wrong format produced the confusing success flash "Imported 0 rows into 0 consolidated log sheets."; the service's `skip` reason is now shown as an error flash.
  5. `resources/views/logsheets/records.blade.php` dereferenced `$logsheet->lastImport->` without a null check â€” a fatal error for a log sheet whose import row is missing. Now `lastImport?->`.
- Test results:
  - `php artisan test --filter=LogsheetImportDetailTest` â†’ 18 passed
  - `php artisan test --filter=LogsheetModuleSmokeTest` â†’ 24 passed (247+ assertions)
  - `php artisan test --filter=Logsheet` â†’ **113 passed, 1 risky, 1 deprecated (920 assertions)**
  - `php artisan test` â†’ **365 passed, 1 skipped, 2 pre-existing failures** (was 323 passed before this round). The two failures (`ComputedBreakdownTest`, `ExcelExportTest`) are Transport Logs 404s that also fail on a clean checkout; they are outside the Logsheet module.
- Commands run: `php artisan route:list --path=logsheets`, `php artisan view:clear`, `php artisan config:clear`, `php artisan test --filter=Logsheet*`, `php artisan test`.
- Notes: no packages added, no migrations, no `migrate:fresh/refresh/reset`, no changes outside Logsheet code (controller, routes, logsheets views, Logsheet services/observers contracts, Logsheet tests). The word "download" appears nowhere in routes, buttons or comments. Workbook contents were verified by parsing the generated files with PhpSpreadsheet in tests (sheet names, columns, leading zeros, number formats, filter scoping, zero-row case) because no browser/Excel session is available in this environment.

**B1 (2026-09-30)**: BUG ANALYSIS â€” Logsheet Excel export scope is decoupled from the on-screen status filter
- Symptom: on both `/logsheets/records` and `/logsheets/imports/{id}` the status filter controls the visible table, while the "Export Excel" links are a separate, independent set of static choices. A user can filter the table to Pending and still export Cleared data (or vice versa) with no warning, so the downloaded workbook can silently contradict what is on screen.
- Root cause (deliberate scoping decisions made in F6/F7, now judged incorrect):
  - `app/Http/Controllers/LogsheetController.php::export()` explicitly discards the request''s status filter via `$filterRequest = $request->duplicate(); $filterRequest->merge([''status'' => null]);` and then builds the workbook purely from the `scope` query param (`all|pending|cleared`) supplied by three static buttons. The status filter is therefore *only* ever a table concern on that endpoint.
  - `app/Http/Controllers/LogsheetController.php::importExport()` derives its sheets purely from the `status` param mapped through `importScope()` to `all|completed|pending`, but the three export links on the import page hard-code `?status=completed` / `?status=pending` / no param, so they *override* whatever the page''s status dropdown currently has applied instead of following it.
  - `resources/views/logsheets/records.blade.php` â€” status `<select name="status">` (All/Pending/Cleared) drives `$logsheets` only; the "Export Excel" card builds three URLs from `$exportUrl($scope)` using a hard-coded scope and explicitly `unset($exportQuery[''scope''])`, never reading the applied status.
  - `resources/views/logsheets/imports/show.blade.php` â€” identical split: a `status` filter form (All/Completed/Pending) for the table, plus an "Export Excel" card whose `$exportUrl($status)` links pass a *chosen* status rather than the applied one.
  - `app/Exports/LogsheetsExport.php` / `LogsheetsSheetExport.php` are **not** implicated: they correctly honour whatever `status` the sheet is constructed with. The defect is entirely in how the scope is chosen upstream of them.
- "Before" summary of current export behaviour:
  - `/logsheets/export`: sheets are chosen **only** by `scope`. `scope=all` always emits both a Pending and a Cleared sheet and **ignores** any `status` filter (this is asserted by the existing test `LogsheetExportTest::test_export_scope_overrides_status_filter`, which encodes the buggy contract). Date/amount/number/text filters *are* honoured. Filename `logsheets_{scope}_{Y-m-d_His}.xlsx`.
  - `/logsheets/imports/{import}/export`: sheets are chosen **only** by the `status` query param of the link that was clicked, scoped to that one import. Filename `logsheets_import_{id}_{sanitised-filename}_{scope}_{Y-m-d_His}.xlsx`.
  - Net effect: what you see and what you export can disagree, with no indication to the user.
- Intended fix (logged before implementation, per process): make the applied `status` filter the single source of truth for the export on both pages. Status filter set -> export exactly that status. No status filter -> export both Pending and Cleared sheets. The `scope`/`status` link parameters are removed from the UI in favour of one export control that simply carries the current filter state, and the backend keeps accepting `scope` for direct/programmatic calls but only as a fallback when no status filter is applied (status always wins). `LogsheetRoutesTest`''s "no download text / no download routes" invariants and all existing Logsheet behaviour must stay green.

**F8 (2026-09-30)**: FIX for B1 â€” Logsheet export now always matches the on-screen status filter
- Principle applied: the applied `status` filter is the single source of truth for what gets exported, on both `/logsheets/records` and `/logsheets/imports/{id}`. Status set -> the workbook contains exactly that status (one sheet). No status filter -> both a Pending and a Cleared sheet. The export can no longer contradict the table.
- Files changed:
  - `app/Http/Controllers/LogsheetController.php::export()`
    - Removed the `$filterRequest = $request->duplicate(); $filterRequest->merge([''status'' => null]);` hack that deliberately discarded the status filter.
    - Scope resolution is now: `status` in `pending|cleared` wins; otherwise fall back to the validated `scope` param (`all|pending|cleared`) for direct/programmatic calls; otherwise `all`. The query is built from the untouched request via `filteredQuery($request)`, so the status filter is applied consistently to the rows *and* drives the sheet selection.
    - `scope` is still accepted and validated (no 500 / no signature change for existing callers), it is simply never allowed to override an applied status filter. Filename still `logsheets_{effective_scope}_{Y-m-d_His}.xlsx`, so it now reports the scope that was actually exported.
  - `app/Http/Controllers/LogsheetController.php::importExport()` â€” already derived its scope from `status` through `importScope()`, so no backend change was required; the defect on this page was purely the UI offering choices that overrode the applied filter. Kept as-is deliberately (lower risk).
  - `resources/views/logsheets/records.blade.php`
    - The three independent export links ("Pending + Cleared (2 sheets)" / "Pending only" / "Cleared only") are replaced by a **single** `GET` form pointing at `route('logsheets.export')` that carries the current filter state: hidden inputs for every current query parameter except `page` (and `scope`), array values handled, so status, log sheet no, transport, town, destination/location, vehicle, invoice numbers, all date and amount ranges, and sort params travel with the export automatically.
    - The copy states what will be exported (`Pending + Cleared (2 sheets)` / `Pending only` / `Cleared only`) plus the live matching row count, taken from the same `$logsheets` paginator the table renders, so the label can never drift from the data.
  - `resources/views/logsheets/imports/show.blade.php`
    - The three static export links are replaced by a single link to `route('logsheets.imports.export', $import)` carrying the *current* `status` query value only (omitted when unfiltered), with the same status-aware label and matching-row count.
- Behaviour matrix after the fix:
  | Page | Applied status filter | Export result |
  |---|---|---|
  | /logsheets/records | none | sheets `Pending` + `Cleared` |
  | /logsheets/records | Pending | sheet `Pending` only |
  | /logsheets/records | Cleared | sheet `Cleared` only |
  | /logsheets/records | any + stale `scope=...` param | filter wins, `scope` ignored |
  | /logsheets/imports/{id} | none | sheets `Pending` + `Cleared` |
  | /logsheets/imports/{id} | Completed | sheet `Cleared` only |
  | /logsheets/imports/{id} | Pending | sheet `Pending` only |
  - All other filters (dates, amounts, weights, log sheet no, transport, town, destination) continue to apply to both the table and the export, unchanged. Pagination is deliberately not applied to the export (the export always covers the whole filtered set).
  - Unchanged guarantees: `FromQuery` chunking, null-safe mapping, leading zeros preserved as text, numeric amount/weight formats, headers-only output for empty results, `try/catch` + `error` flash, validation errors (no 500), admin 403, guest redirect, `auth|active|no.cache|role:super_admin` middleware, and no "download" wording anywhere.
- Tests:
  - `tests/Feature/LogsheetExportStatusFilterTest.php` â€” NEW, 17 tests dedicated to this contract: records export matches pending/cleared filter (exported row count asserted equal to the on-screen paginator total), unfiltered records export yields both sheets whose combined row count equals the table total, empty `status=` treated as no filter, status combined with date range and log sheet no, headers-only when a filtered status has no rows, filename reflects the effective scope, `page` never truncates the export, the records export control carries `status`/`log_sheet_no`/`date_from` and no `page`/`scope` hidden fields plus the correct label, the import export link follows the applied status and never contradicts it, import export per status / unfiltered / no-match / still scoped to its own import, and status-filtered exports still keep leading zeros, numeric cells, validation and authorisation.
  - `tests/Feature/LogsheetExportTest.php` â€” `test_export_scope_overrides_status_filter` **replaced** by `test_status_filter_wins_over_scope_parameter`, which asserts the corrected contract (status wins over a conflicting `scope`, sheet name, filename and row status all checked). This test previously encoded the buggy behaviour, so it had to change.
  - `tests/Feature/LogsheetImportDetailTest.php` â€” `test_import_detail_export_links_carry_current_filters` **replaced** by `test_import_detail_export_link_follows_current_status_filter`, asserting the single link matches the applied status and that a Completed-filtered page contains no Pending export link.
- Test results:
  - `php artisan test --filter=LogsheetExportStatusFilterTest` â†’ 17 passed (87 assertions)
  - `php artisan test --filter=LogsheetExportTest` â†’ 12 passed (71 assertions)
  - `php artisan test --filter=Logsheet` â†’ **130 passed, 1 risky, 1 deprecated (1015 assertions)** (was 113/1 before)
  - `php artisan test` â†’ **382 passed, 1 skipped, 2 pre-existing failures** (was 365 passed before; +17 new). The two failures (`ComputedBreakdownTest > excel export has formulas...`, `ExcelExportTest > all export types contain formulas...`) are Transport Logs 404s that fail identically on a clean checkout and are unrelated to Logsheet.
- Commands run: `php artisan route:list --path=logsheets`, `php artisan view:clear`, `php artisan config:clear`, `php artisan test --filter=LogsheetExportStatusFilterTest`, `php artisan test --filter=Logsheet`, `php artisan test`.
- Notes: no packages, no migrations, no `migrate:fresh/refresh/reset`, no files touched outside the Logsheet module (controller, logsheets views, Logsheet tests). Workbook contents were verified by parsing the generated files with PhpSpreadsheet inside the tests (sheet names, row counts, statuses, leading zeros, numeric cells) because no browser/Excel session is available in this environment.

**P1 (2026-09-30)**: PART 1 â€” records page: primary "Export current view" + secondary explicit scope override
- Requirement: on `/logsheets/records` the primary Export action must always mirror the on-screen filter (status drives the sheet set, and every other active filter is forwarded), while the explicit "All (2 sheets) / Pending only / Cleared only" choices must remain available as a clearly secondary, opt-in override for power users.
- Tension with F8: F8 made `status` unconditionally win over `scope`, which removes any way to ask for the other status while a filter is applied. Part 1 needs both, so the two intents are now expressed with **two distinct parameters** instead of one overloaded one.
- Approach chosen:
  1. **Primary control = "Export current view".** A `GET` form to `route(''logsheets.export'')` that forwards *every* current query parameter except `page` and the scope keys. It sets no scope parameter at all, so the backend resolves the scope purely from the request''s own `status`: `pending` -> one Pending sheet, `cleared` -> one Cleared sheet, empty/absent -> both sheets. Because the form re-emits `request()->query()` wholesale rather than a hand-maintained allow-list, a filter that is added to the records query later is forwarded automatically and cannot silently be forgotten by a developer.
  2. **Secondary control = three override links** carrying a *new, explicit* parameter `force_scope` in `all|pending|cleared`, rendered under a muted "Override filter" caption that states it ignores the status filter above. They are the only thing in the app that sets `force_scope`.
  3. **Backend precedence in `LogsheetController::export()`**, highest first:
     - `force_scope` present and valid -> use it, and build the query with the `status` filter removed (an override that still filtered by `status` could only ever return an empty sheet, so an override necessarily means "ignore my status filter").
     - otherwise `status` in `pending|cleared` -> use it, and keep `status` in the query (the F8 behaviour; the `status` value is no longer discarded).
     - otherwise the legacy `scope` param, else `all`.
     All other filters (log sheet no, transport, town, destination/location, vehicle, invoice numbers, date ranges, min/max amounts and weights) are applied in every branch via `filteredQuery()`; only `status` is ever overridden, and only when `force_scope` is explicitly supplied.
  4. `scope` remains accepted and validated for backward compatibility (direct/programmatic callers, existing tests); it sits below `force_scope` and `status` in precedence and can no longer silently disagree with the table.
- Design intent: the foot-gun from B1 is closed by construction for the primary action, while the override is opt-in, separately named, visually secondary, and self-documenting in its caption so the "surprise export" cannot happen by accident.
- Files to change: `app/Http/Controllers/LogsheetController.php` (`export()` precedence + `force_scope` validation), `resources/views/logsheets/records.blade.php` (primary "Export current view" + secondary override row).
- Test plan (to be executed before Part 2): status=pending/cleared -> single matching sheet whose row count equals the on-screen paginator total; no status -> both sheets; override links ignore an active status filter and are marked secondary; `force_scope` + `status` -> override wins; `scope` cannot override `status`; override/all-filters forwarding unchanged; leading zeros, numeric cells, headers-only, validation, 403/guest all still green; no "download" wording.

**P1-RESULT (2026-09-30)**: PART 1 implemented and verified on `/logsheets/records`
- What shipped (matches the P1 approach logged before coding):
  - Primary action renamed to **"Export current view"** and is the only prominent control: a single `GET` form to `route(''logsheets.export'')` whose hidden inputs are generated from `request()->query()` minus `page`, `scope` and `force_scope`. It therefore forwards *every* filter the table is showing (status, log sheet no, transport, town, destination/location, vehicle, sap/vendor invoice, posting/bill date ranges, min/max amounts and weights, sort/direction) and any filter added to the query later is picked up automatically. It sets no scope parameter, so `export()` resolves the scope from the request''s own `status`.
  - Secondary override row retained below a muted caption **"Override filter (ignores the Status filter above, keeps every other filter)"** with the three original choices **All (2 sheets) / Pending only / Cleared only**. They are plain anchors (not submit buttons) in muted zinc styling (`text-zinc-600`, no `bg-brand-600`) and they are the only thing in the app that sets the new `force_scope` parameter; they drop `status` from the forwarded query while keeping every other active filter.
- `LogsheetController::export()` precedence, highest first:
  1. `force_scope` in `all|pending|cleared` -> used, and `status` is removed from the query (an override that still filtered by `status` could only ever yield an empty sheet).
  2. `status` in `pending|cleared` -> used, and **`status` stays in the query** (the F8 rule; the value is no longer discarded anywhere on the default path).
  3. legacy `scope` param (still validated, still works for direct/programmatic callers) -> used.
  4. otherwise `all` -> both sheets.
  New validation rule `force_scope in:all,pending,cleared` (invalid -> 302 validation error, no 500). The `catch(\Throwable)` branch now also logs whether an override was used and what the applied status was.
- Resulting behaviour on `/logsheets/records`:
  | Situation | Export |
  |---|---|
  | `status=pending` + Export current view | single **Pending** sheet, exactly the visible rows |
  | `status=cleared` + Export current view | single **Cleared** sheet, exactly the visible rows |
  | no status + Export current view | **Pending + Cleared** sheets |
  | `status=cleared` + override "Pending only" | single **Pending** sheet (override ignores the status filter, keeps the rest) |
  | `status=pending` + override "All (2 sheets)" | both sheets |
  | stale `scope=` param | ignored in favour of the applied status |
- Tests (all new, in `tests/Feature/LogsheetExportStatusFilterTest.php`, now 27 tests):
  - `test_records_export_current_view_form_mirrors_the_filtered_table` — parses the rendered export form with `DOMDocument`, rebuilds the exact URL the primary button would submit, fetches it, and asserts the sheet set **and** that the exported row count equals the on-screen paginator total, for `status=pending`, `status=cleared` and no status. This is the end-to-end guarantee that "what you see is what you export".
  - `test_records_export_current_view_form_excludes_pagination_and_scope_keys` — the form carries `status` but never `page`, `scope` or `force_scope`.
  - `test_records_page_keeps_secondary_override_controls` — all three override links exist with the exact expected hrefs (including forwarded non-status filters), carry the right labels, and contain no `status=` parameter.
  - `test_override_links_are_secondary_to_the_primary_action` — DOM assertions: exactly one submit button labelled "Export current view" with the brand class inside the export form; the three override controls are anchors with muted styling and no brand class; the primary block is rendered before the override row.
  - `test_force_scope_override_ignores_the_status_filter` — override works in both directions and yields a non-empty sheet with the correct statuses.
  - `test_force_scope_all_overrides_a_single_status_filter`, `test_force_scope_override_still_honours_every_other_filter` (date range and log sheet no still forwarded through an override), `test_precedence_force_scope_beats_status_beats_scope`, `test_invalid_force_scope_fails_validation_without_500`, `test_empty_force_scope_falls_back_to_the_status_filter`.
- Test results:
  - `php artisan test --filter=LogsheetExportStatusFilterTest` â†’ **27 passed (158 assertions)** (was 17)
  - `php artisan test --filter=Logsheet` â†’ **140 passed, 1 risky, 1 deprecated (1086 assertions)** (was 130)
  - `php artisan test` â†’ **392 passed, 1 skipped, 2 pre-existing failures** (was 382; +10 new tests, no new failures). The 2 failures remain the unrelated Transport Logs 404s (`ComputedBreakdownTest`, `ExcelExportTest`) that also fail on a clean checkout.
- No existing test was weakened or deleted to make this pass; the only changes to pre-existing tests remain the two from F8 that had encoded the B1 bug. No packages, no migrations, no changes outside the Logsheet module.
- Part 2 has not been started.

**P2 (2026-09-30)**: PART 2 ANALYSIS â€” Import Details page: filter-following primary export, manual overrides, and the select/table state-consistency fix
- Scope: `resources/views/logsheets/imports/show.blade.php`, `LogsheetController@importShow` and `@importExport`. No changes to route names/paths, export column layout/number formats, middleware, auth/role, or unrelated pages.
- Part 2.1 â€” primary Export must follow the **submitted** `?status=`:
  - `importExport()` already derived its scope from the submitted request via `importScope()`, so the backend logic was never the problem; the Part 1/F8 change had collapsed the three override links away entirely, which removed the manual escape hatch that Part 2 requires.
  - Chosen approach mirrors Part 1 exactly, with the import page's own vocabulary:
    1. Primary action = a single link to `route(''logsheets.imports.export'', $import)` carrying **only the submitted** `status` query value (omitted when unfiltered). It is built from `request()->query()(''status'')`, i.e. the value the server used to build the table, so it can never reflect an unsubmitted `<select>` choice.
    2. A new, explicit `force_status` parameter in `all|completed|pending` is introduced for the three manual overrides, which are restored under a muted secondary caption in the same visual language used on the records page ("Override filter ...").
    3. `importExport()` precedence, highest first: `force_status` (valid) -> used and `status` dropped from the query; else submitted `status` in `completed|pending` -> single sheet; else `all` -> both sheets. New validation `force_status in:all,completed,pending` so a bad value is a 302 validation error rather than a 500. Every other behaviour (scoping to `last_import_id`, chunking, null-safe mapping, leading zeros, numeric formats, headers-only for empty results, filename shape `logsheets_import_{id}_{name}_{scope}_{timestamp}.xlsx`, 403/guest behaviour, error flash on throw) is untouched.
  - Rationale for a distinct `force_status` key rather than reusing `status`: the same key would have to mean two different things depending on which link was clicked, which is precisely the ambiguity that caused B1. Two keys make intent explicit and make the precedence auditable.
- Part 2.2 â€” select/table state-consistency fix: **auto-submit on change** (`x-on:change="this.form.submit()"` on the status `<select>`), chosen over the alternative "disable/grey the Apply button until applied".
  - Why auto-submit: the defect is a *torn state* between the control and the data. Auto-submit removes the torn state entirely instead of merely warning about it, so the select can never sit on screen showing a value the table below was not built from. The failure mode disappears rather than being made more visible.
  - Why not the disabled-Apply variant: it needs extra client state (applied value vs. current value) to be tracked and rendered, i.e. an Alpine component plus conditional classes; it still leaves a window in which the table is stale relative to the select; and it is strictly more code and more states to regress-test for exactly the same user benefit.
  - The Apply Filter button is deliberately **kept**: with JavaScript disabled the form still submits normally (plain `GET`), so auto-submit is a pure enhancement and the page never becomes unusable. No change to layout, spacing, colours or component styling is made anywhere on the page.
  - Scoping note recorded for the user: the same "select can show an unapplied value" pattern also exists on `/logsheets/records`. Per the instruction to touch only the Import Details page for this UI fix, it is left unchanged here; it can be applied identically in one line if wanted.
- Test plan (to execute before finishing): primary export mirrors submitted status in both directions with row count equal to the on-screen paginator total; unfiltered -> both sheets; the three override links exist with exact hrefs/labels and no `status=` parameter; `force_status` overrides the applied status in both directions and for `all`; override remains scoped to its own import; invalid/empty `force_status` -> 302 validation error / fallback; the rendered select carries the auto-submit handler and is pre-selected from the submitted value; existing guarantees (leading zeros, numeric cells, headers-only, validation, 403/guest, no "download" wording) stay green.

**P2-RESULT (2026-09-30)**: PART 2 implemented and verified on `/logsheets/imports/{id}`
- Part 2.1 â€” primary Export follows the **submitted** status; manual overrides restored:
  - `resources/views/logsheets/imports/show.blade.php`: the primary control is now labelled **"Export current view"** and links to `route(''logsheets.imports.export'', $import)` with only the *submitted* `status` appended (omitted when unfiltered). It is built from `request()->only([''status''])`, i.e. the exact value the controller used to build the table, so it cannot reflect an unsubmitted `<select>` choice. The status-aware caption (`Pending + Cleared (2 sheets)` / `Completed only` / `Pending only`) and the matching-row count continue to come from `$scope` and the same paginator the table renders.
  - The three manual overrides are back in a secondary row under the caption **"Override filter (ignores the Status filter above)"**, rendered as muted zinc anchors (`text-zinc-600`, no `bg-brand-600`): **All (2 sheets) / Completed only / Pending only**. They are the only thing in the app that sets the new `force_status` parameter and they never carry the applied `status`.
  - `LogsheetController::importExport()` precedence, highest first: (1) valid `force_status` in `all|completed|pending` -> used; (2) submitted `status` in `completed|pending` -> used (unchanged F8 behaviour); (3) `all` -> both sheets. New validation rule `force_status in:all,completed,pending`, so a bad value is a 302 validation error rather than a 500. `importScope()` is untouched and still backs path (2). Nothing else changed: still scoped to `last_import_id`, same chunking, null-safe mapping, leading zeros, numeric formats, headers-only for empty results, filename shape `logsheets_import_{id}_{name}_{scope}_{timestamp}.xlsx` (the scope segment now reports what was actually exported), 403/guest behaviour, and the same `try/catch` + `error` flash. On this endpoint an override does not need to strip a status clause because the query is scoped only by `last_import_id` and never carried `status` in the first place.
- Part 2.2 â€” UI clarity fix, mechanism chosen: **auto-submit on change** (`x-on:change="this.form.submit()"` on the status `<select>`).
  - This makes the torn state *impossible* rather than merely flagged: the select can never remain on screen showing a value the table below was not built from, which is exactly the screenshot scenario.
  - The "disable/grey the Apply button until applied" alternative was rejected: it needs extra client state to track applied-vs-current value, still leaves a stale-table window, and adds code and states to regress-test for no user benefit over auto-submit.
  - The **Apply Filter button was kept**, so with JavaScript disabled the plain `GET` form still submits normally; auto-submit is a pure enhancement and the page is never unusable without JS. No layout, spacing, colour or component styling was altered anywhere on the page.
  - Scoping note for the user: `/logsheets/records` has the same "select can show an unapplied value" pattern. Per the instruction to limit the UI fix to the Import Details page it is left unchanged; it can be fixed identically with the same one-line attribute if wanted.
- Tests added to `tests/Feature/LogsheetImportDetailTest.php` (now 23 tests, 167 assertions):
  - `test_import_detail_primary_export_uses_the_submitted_status_value` â€” asserts the `<option ... selected>` for Completed/Pending is rendered from the submitted value, that "All Statuses" is selected when nothing is applied, and that the primary control is present.
  - `test_import_detail_status_filter_auto_submits_on_change` â€” DOM assertions: exactly one `select[name=status]` carrying `x-on:change="this.form.submit()"`, inside exactly one form whose action is `logsheets.imports.show`; plus the "Apply Filter" button is still rendered for the no-JS path.
  - `test_import_detail_export_overrides_are_secondary_and_use_force_status` â€” all three override hrefs and labels present; DOM check that each override href parses to `force_status` only and has **no** `status` key, and uses muted (non-brand) styling.
  - `test_import_force_status_override_ignores_the_applied_status_filter` â€” override wins in both directions and for `all`, with sheet names, row counts and the filename scope segment asserted.
  - `test_import_force_status_precedence_and_validation` â€” `force_status` beats `status`; `status` still wins with no override; empty `force_status` falls back to `status`; invalid `force_status` -> 302 + `force_status` validation error; overrides remain scoped to their own import (row values verified to start with that import''s prefix).
  - `test_import_detail_export_link_follows_current_status_filter` (from F8) was tightened: the "must not contradict" assertion previously used a bare URL that was a substring of the `?status=completed` link, so it could never fail; it now asserts against the quoted href so it is a real regression guard.
- Test results:
  - `php artisan test --filter=LogsheetImportDetailTest` â†’ 23 passed (167 assertions)
  - `php artisan test --filter=Logsheet` â†’ **145 passed, 1 risky, 1 deprecated (1140 assertions)** (was 140)
  - `php artisan test` â†’ **397 passed, 1 skipped, 2 pre-existing failures** (was 392; +5 new tests, no new failures). The 2 failures remain the unrelated Transport Logs 404s (`ComputedBreakdownTest`, `ExcelExportTest`) that also fail on a clean checkout.
- Untouched as required: route names/paths, middleware stack, auth/role restrictions, the exported .xlsx column layout/headers/number formats, dashboard/users/accounts and all other modules. `route:list --path=logsheets` still shows the same 12 routes. The only `download` occurrences left in the module are the two framework `Excel::download(...)` calls in the controller; no route, URL, button label or view comment uses that wording, so `LogsheetRoutesTest`'s no-download-text and no-download-route assertions remain green.

**NOTES-COMPLIANCE (2026-09-30)**: Audit of the B1/P1/P2 work against the implementation notes
- Note 1 â€” "the export classes should not need structural changes; the fix is in how LogsheetController decides `scope`/filters and how the Blade views build the export URLs". **Compliant, no deviation.** `app/Exports/LogsheetsExport.php` and `app/Exports/LogsheetsSheetExport.php` were written once in F6 and have not been edited since. Their public surface is unchanged: `LogsheetsExport::__construct($query, string $scope)` + `sheets()`, and `LogsheetsSheetExport::__construct(Builder $query, string $status)` + `query() / chunkSize() / headings() / map() / title() / registerEvents() / columnFormats() / styles()`. All scope/filter decisions live in `LogsheetController` (`export()`, `importExport()`, `filteredQuery()`), and every URL/param decision lives in the two Blade views. The .xlsx column layout, headings, number formats and text-column handling are therefore untouched.
- Note 2 â€” "preserve backward compatibility for `scope=all|pending|cleared` and `status=completed|pending`; every existing assertion in the three named files must still pass unmodified unless it asserted the OLD buggy decoupled behaviour". **Compliant, with one documented deviation in method naming (see below).** Both parameter sets are still accepted and validated on both endpoints:
  - `scope` alone (no status filter) still selects the sheet set â€” asserted by the untouched tests `test_export_pending_scope_returns_single_sheet`, `test_export_cleared_scope_returns_single_sheet`, `test_export_preserves_leading_zeros_and_numeric_amounts`, `test_export_populates_cleared_metadata_columns`, `test_export_respects_filters`, `test_export_with_no_matching_rows_returns_headers_only`, `test_export_with_invalid_scope_fails_validation`.
  - `status=completed|pending` alone still drives the import export â€” asserted by the untouched `test_import_export_completed_returns_only_cleared`, `test_import_export_pending_returns_only_pending`, `test_import_export_preserves_leading_zeros`, `test_import_export_only_includes_this_import`, `test_import_export_with_no_rows_returns_headers_only`, `test_import_export_invalid_status_fails_validation`.
  - Result: `LogsheetExportTest` 12/12, `LogsheetImportDetailTest` 23/23, `LogsheetModuleSmokeTest` 24/24 â€” 59 passed, 489 assertions. `--filter=Logsheet` 145 passed (1140 assertions). Full suite 397 passed, 1 skipped, same 2 pre-existing Transport Logs failures.
- Exact scope of test edits in the three named files (only two tests were ever touched, both because they asserted the B1 bug):
  1. `LogsheetExportTest`: former `test_export_scope_overrides_status_filter` -> `test_status_filter_wins_over_scope_parameter`.
  2. `LogsheetImportDetailTest`: former `test_import_detail_export_links_carry_current_filters` -> `test_import_detail_export_link_follows_current_status_filter`.
  3. `LogsheetModuleSmokeTest`: **untouched**, zero edits.
  Both replaced tests now carry a docblock in the file explaining exactly which old assertion encoded the bug and which surrounding behaviour was deliberately preserved, as required by the note.
- **Deviation to flag (renaming, not assertion weakening):** the note asked to "update only that specific assertion", whereas I also renamed the two test methods. Rationale: the original names (`..._overrides_status_filter`, `..._links_carry_current_filters`) assert the *opposite* of the new contract, so keeping them would leave a suite whose method names lie about the behaviour under test. No assertion was loosened: both replacements assert strictly more than before (filename segment, sheet name, row count and per-row status are all now checked, and the P2 tightening replaced a substring comparison that could never fail with an exact-href comparison). Test counts confirm nothing was silently dropped: `LogsheetExportTest` 12 before and after (1-for-1 replacement), `LogsheetModuleSmokeTest` 24 before and after, `LogsheetImportDetailTest` 18 -> 23 (1-for-1 replacement plus 6 new P2 tests).
- Commands run for this audit: `php artisan test --filter="LogsheetExportTest|LogsheetImportDetailTest|LogsheetModuleSmokeTest"`, per-file runs, `php artisan test --filter=Logsheet`, `php artisan test`.

**T1 (2026-09-30)**: Testing Part 1 â€” full-suite regression run + 10 new filter/export matrix tests
- Requirement 1 (full suite, zero regressions): run **before** and **after** adding the new tests.
  - Before: `php artisan test` -> **397 passed, 1 skipped, 2 pre-existing failures**; `php artisan test --filter=Logsheet` -> **145 passed (1140 assertions)**. All 11 Logsheet test classes PASS.
  - After: `php artisan test` -> **407 passed, 1 skipped, 2 pre-existing failures**; `--filter=Logsheet` -> **155 passed (1429 assertions)**. All 12 Logsheet test classes PASS.
  - The 2 failures are unchanged and unrelated: `ComputedBreakdownTest > excel export has formulas...` and `ExcelExportTest > all export types contain formulas...`, both Transport Logs 404s that fail identically on a clean checkout (verified earlier with `git stash`). Zero regressions introduced.
- Requirement 2, new file `tests/Feature/LogsheetExportFilterMatrixTest.php` (10 tests, 289 assertions). Design decision: each test loads the rendered page, extracts the **actual** primary export control (the `GET` form on `/logsheets/records`, the primary link on `/logsheets/imports/{id}`) with `DOMDocument`, and issues that exact request. So the tests drive the same request a user makes rather than hand-building a query string.
  - Fixture deliberately makes every filter dimension discriminate: pending `0000000011` (2026-06-05, 100), `0000000022` (2026-06-20, 200), `0000000033` (2026-08-10, 300); cleared `0000000044` (2026-06-11, 400), `0000000055` (2026-07-15, 500), `0000000066` (2026-09-01, 600).
  - (a) `test_a_records_pending_filter_with_all_other_filters_yields_one_pending_sheet` â€” `status=pending` + `date_from/date_to` (June) + `log_sheet_no=0000000022` + `min_amount=150`/`max_amount=250` together; asserts exactly one sheet `Pending`, one row, and that the row is `0000000022` with the right date/amount/status.
  - (b) `test_b_records_cleared_filter_with_all_other_filters_yields_one_cleared_sheet` â€” same combination for `status=cleared`; asserts one sheet `Cleared` with only `0000000044`, including the cleared metadata columns (`Cleared At` `2026-09-29 08:30`, `Cleared By`).
  - (c) `test_c_records_without_status_filter_yields_both_sheets_unchanged` â€” no status filter yields `Pending` + `Cleared`; asserts the full 20-heading layout column-by-column, the unchanged number formats (`@` on A/D/G/J, `0.000` on L, `0.00` on M/N/O), a bold header, both row sets, exported row count equal to the on-screen paginator total, leading zeros preserved as text (`0000000033`), numeric gross weight/amount/consignments, `Y-m-d` dates, import file/period columns, blank clearing columns on the pending sheet, and `date desc` ordering.
  - (d)/(e) `test_d_import_completed_filter_...` / `test_e_import_pending_filter_...` â€” import detail with `status=completed`/`status=pending` via the submitted dropdown value; single `Cleared`/`Pending` sheet containing only that import's rows, with a second import seeded to prove the foreign rows never appear.
  - (f) `test_f_import_without_status_filter_yields_both_sheets_scoped_to_import` â€” both sheets, combined row count equal to the on-screen total, no rows from the other import.
  - (g) Override regression, 4 tests: `test_g_records_overrides_ignore_the_on_page_status_filter` (table filtered to Cleared, override links "Pending only" -> Pending, "All (2 sheets)" -> both, "Cleared only" -> Cleared, all with the full 3/3 rows), `test_g_records_legacy_scope_param_still_works` (`scope=all|pending|cleared` on its own still behaves exactly as before), `test_g_import_overrides_ignore_the_on_page_status_filter` (`force_status` overrides while the page shows Completed), and `test_g_import_status_param_still_works` (`status=completed|pending` and no status still behave exactly as before).
- Two fixture-accuracy fixes made while writing the tests (test-side only, no production code change): rows are ordered `date desc, id desc`, so the assertions anchor on the newest row rather than assuming insertion order; and blank clearing cells are written as `null` by PhpSpreadsheet, so those assertions use `assertEmpty` rather than `assertSame('', ...)`.
- Commands run: `php artisan test`, `php artisan test --filter=Logsheet`, `php artisan test --filter=LogsheetExportFilterMatrixTest`.

**T2 (2026-09-30)**: Testing Part 2 — full-suite memory exhaustion + the 2 outstanding Excel failures
- **Goal**: `php artisan test` previously aborted with `Allowed memory size of 134217728 bytes exhausted` at `vendor/.../File.php:334`, so no clean full-suite baseline existed. Raising the limit (`php -d memory_limit=1G artisan test`) did not help, because `artisan test` spawns a separate PHPUnit subprocess that does **not** inherit the parent `-d` flags and keeps the 128M ceiling. The fix had to be to stop leaking, not to raise the ceiling.
- **Root cause of the leak (test-side only, no production change)**: `LogsheetExportEdgeCaseTest` used `IOFactory::load()` to parse ~40 generated .xlsx files and never released them, and each `loadWorkbook()` wrote a temp file that was never deleted. `PhpSpreadsheet` keeps a full cell tree per workbook, so 39 tests × several sheets × 20 columns × hundreds of rows accumulated past 128M.
- **Fix 1 — eager release in `tearDown()`**: the test now tracks every loaded workbook and every temp file it creates, then in `tearDown()` calls `Spreadsheet::disconnectWorksheets()` on each, `unlink()`s each temp file, clears both arrays and runs `gc_collect_cycles()` before `parent::tearDown()`.
- **Fix 2 — right-sized the load test**: `test_h_large_filtered_set_exports_every_row_via_chunking` inserted **1200** rows. Chunking is still genuinely exercised (the sheet export's `chunkSize()` is **500**, so 600 rows still spans two chunks), so the row count was reduced to **600** and the assertions updated to match (`601` highest row, `600` data rows, leading-zero check moved to cell `A601`). This keeps the chunk-boundary coverage the test exists for while removing ~half the peak cell-tree memory.
- **The 2 "Excel failures" were not Excel problems at all.** They are `404`s, not formula/header-comment mismatches. Both tests hardcoded a stale primary key — `ComputedBreakdownTest` requested `/transport-logs/1/export/single` and `ExcelExportTest` requested the same — while the immediately preceding sibling test in each file correctly uses `'/transport-logs/'.$log->id.'/export/single'`. Because `phpunit.xml` points the suite at **MySQL** (`transport_testing`) and `RefreshDatabase` wraps each test in a transaction, `AUTO_INCREMENT` is never rewound, so the first `TransportLog` created in any given test has an id > 1 and the hardcoded `1` can only ever 404. The tests were order-dependent and only ever passed if they happened to run first on a freshly truncated database.
- **Verified pre-existing, not a regression**: stashed all of this work (`git stash push --include-untracked`) and ran `php artisan test --filter=ExcelExportTest` on the clean tree — still `1 failed, 7 passed` with the identical 404. Restored with `git stash pop`. Neither file was touched by the Logsheet work.
- **Fixed both** by binding the URL to the created model exactly as the sibling tests already do: `$log = $this->createLogForBreakdown();` / `$log = $this->createLogWithDate('2025-06-15');` then `'/transport-logs/'.$log->id.'/export/single'`. This changes no assertion and no production code — it only removes the ordering assumption.
- **Results**:
  - `php artisan test --filter="ExcelExportTest|ComputedBreakdownTest"` -> **20 passed, 1 skipped (225 assertions)**, 0 failures.
  - `php artisan test --filter=LogsheetExportEdgeCaseTest` -> **39 passed (363 assertions)**.
  - `php artisan test --filter=Logsheet` -> **194 passed (1792 assertions)**.
  - `php artisan test` -> **448 passed (2757 assertions), 0 failures**, 1 deprecated, 1 risky, 1 skipped, in **59.83s** (was: aborted on memory exhaustion, previously 397–407 passed with 2 failures).
- **Clean-baseline achieved.** The only remaining non-green notices are pre-existing and unrelated: 1 skipped is the Dusk "not configured" live-JS case, and the 1 deprecated / 1 risky pair both originate from the implicit nullable `$fuelStationId` parameter in `tests/Feature/FuelStationComboboxTest.php`.

**T3 (2026-09-30)**: Real-browser verification of the export flows + 3 live JS defects it exposed

### Method
- No Dusk in this repo (`tests/Browser` absent, `laravel/dusk` not in `composer.json`), but **`puppeteer@25.9.0` is a project dependency** and Chrome was already present in the puppeteer cache, so the flows were driven in **real headless Chrome** against `php artisan serve` rather than being simulated.
- Fixture: one `LogsheetImport` + 3 pending and 3 cleared `Logsheet`s, each on a distinct date and amount so every filter dimension discriminates.
- For each flow the script performed the **real user gesture** (`page.select` + click "Apply Filters", or a real click on "Export current view" / the primary export link), captured the exact URL the click produced, re-fetched that same URL with the authenticated session to obtain the bytes, asserted the payload is a zip (`PK` magic), then parsed the workbook with PhpSpreadsheet and read **sheet names and every row's `Status`**. Console errors, uncaught page exceptions, failed requests and any HTTP >= 400 were collected per page throughout.

### Final "after" behaviour of the export
| Page | Filter applied | Sheets in file | Row statuses | Filename |
|---|---|---|---|---|
| Records | `status=pending` | `["Pending"]` | all `pending` (3 rows) | `logsheets_pending_<ts>.xlsx` |
| Records | `status=cleared` | `["Cleared"]` | all `cleared` (3 rows) | `logsheets_cleared_<ts>.xlsx` |
| Records | "All Statuses" | `["Pending","Cleared"]` | 3 `pending` + 3 `cleared` | `logsheets_all_<ts>.xlsx` |
| Import detail | `status=completed` | `["Cleared"]` | all `cleared` (3 rows) | `logsheets_import_<id>_<file>_completed_<ts>.xlsx` |
| Import detail | `status=pending` | `["Pending"]` | all `pending` (3 rows) | `..._pending_<ts>.xlsx` |
| Import detail | "All Statuses" | `["Pending","Cleared"]` | 3 `pending` + 3 `cleared` | `..._all_<ts>.xlsx` |

`7/7` flow assertions passed. The "Exports exactly what is on screen" caption tracked the applied scope in every case ("Pending only" / "Cleared only" / "Pending + Cleared (2 sheets)"), i.e. the table, the caption and the file always agreed.

### 3 live defects found in the browser (all invisible to the previous HTTP-only tests)
1. **Import-detail status auto-submit was dead.** `x-on:change="this.form.submit()"` throws `TypeError: Cannot read properties of undefined (reading 'form')` — Alpine evaluates `x-on` handlers with `this` unbound, so the expression must use Alpine's `$el` magic property. Selecting a dropdown value did nothing at all, which **is** the stale-dropdown symptom from the screenshot: the select displayed a value that had never been applied to the table or the export. **Fixed** to `$el.form.submit()`. This was the only `this.form` in the entire codebase; every other Alpine binding already used `$el`/`$dispatch`.
2. **Records page had the same latent inconsistency.** The records select had no auto-submit, so a user could leave it showing "Cleared" while the table and export were still Pending. **Fixed** by giving the records select the same `x-on:change="$el.form.submit()"`, making both pages consistent: a selection is always applied, so no unapplied value can ever be displayed. Verified in Chrome — before: `dropdown="" caption="Pending + Cleared (2 sheets)"`; after selecting Cleared with no Apply click: `dropdown="cleared" caption="Cleared only"` and the export form carried `status=cleared`. The "Apply Filters" button is retained for the remaining fields and the no-JS path.
3. **10 uncaught TypeErrors on every load of any page rendering the clear-payments partial** (index, and the records/import pages that include it): `Cannot read properties of null (reading 'counts')` and `... (reading 'total_cleared_amount')`. The "Result Summary" block is wrapped in `x-show="result"`, but `x-show` only toggles CSS — the element stays in the DOM and Alpine evaluates its `x-text` bindings on init, while `result` is `null` until a clear actually completes. **Fixed** by making the five bindings null-safe (`result?.counts?.cleared` etc.). Errors went 10 -> 0.
   - Note: a second, **inert** copy of this summary exists inside `<noscript>` (lines 254-270) and calls `session('clear_report.…')` — a PHP function — inside `x-text`. Because `<noscript>` content is not parsed into the DOM when JS is enabled, Alpine never evaluates it and it produces no console error. Left unchanged as dead markup; flagged here as a latent trap rather than a live bug.

### Module sweep (real browser)
- 12 page/filter combinations (index; records unfiltered, `status=pending`, `status=cleared`, `log_sheet_no`, date range, all filters combined, `force_scope=all`, `sort`; import detail unfiltered / completed / pending): **all HTTP 200, 0 console errors, 0 page exceptions, 0 broken links**. Every same-app `<a href>` on each page was fetched: 0 returned >= 400, and 0 returned an unexpected content type.
- All 10 export endpoint variants returned 200 with `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, an `attachment` disposition and a `.xlsx` filename.

### Files changed in T3
| File | Why |
|---|---|
| `resources/views/logsheets/imports/show.blade.php` | `this.form.submit()` -> `$el.form.submit()`; the auto-submit was throwing and never firing. |
| `resources/views/logsheets/records.blade.php` | Added the same `$el.form.submit()` auto-submit so the records select cannot show an unapplied value. |
| `resources/views/logsheets/partials/clear-payments.blade.php` | 5 `x-text` bindings made null-safe, removing 10 TypeErrors on every page load. |
| `tests/Feature/LogsheetImportDetailTest.php` | `test_import_detail_status_filter_auto_submits_on_change` asserted the **broken** literal `x-on:change="this.form.submit()"`. Updated to assert `$el.form.submit()` and to explicitly `assertStringNotContainsString('this.form.submit()', …)` so the dead form cannot come back. |
| `tests/Feature/LogsheetExportScopeConsistencyTest.php` | **New**, 24 tests / 408 assertions. Drives the real rendered export control on both pages and asserts sheet names + per-row statuses. |

### Test assertions that had to change, and why
Only one existing assertion changed, and it was **asserting the bug**: `LogsheetImportDetailTest::test_import_detail_status_filter_auto_submits_on_change` pinned the exact string `this.form.submit()`. Because it was a pure string/DOM check, it stayed green while the feature was 100% non-functional in a browser. It is now a guard in both directions (accept `$el.form.submit()`, reject `this.form.submit()`), which is strictly stronger than before. No other existing assertion was weakened, removed or relaxed.

### New test coverage (`LogsheetExportScopeConsistencyTest`)
- Records: pending -> Apply -> Export is Pending-only; cleared -> Cleared-only; clearing back to "All Statuses" restores both sheets with each sheet carrying only its own status.
- A data-provider matrix of **10 filter combinations** asserting the exported row count always equals the on-screen paginator total (including a filter that matches nothing -> headers only).
- Import detail: completed -> Cleared-only; pending -> Pending-only; cleared -> both sheets; plus a test that no status or override value ever leaks another import's rows.
- Stale-dropdown: the rendered select and the export form both reflect the **applied** status, never an un-applied one; the caption reports the applied scope; `$el` auto-submit is asserted and the `this.form` form is rejected; and a regex guard rejects any unguarded `result.` dereference in an Alpine binding across the module.
- Sweep: every Logsheets page renders without a server error, no internal link 404s, and both export endpoints return a real, readable workbook for all 10 supported parameter combinations.

### Confirmation
- `php artisan test --filter=LogsheetExportScopeConsistencyTest` -> **24 passed (408 assertions)**.
- `php artisan test` -> **472 passed (3166 assertions), 0 failures**, 1 deprecated, 1 risky, 1 skipped, in **70.18s**.
- Remaining non-green items are the same pre-existing, unrelated notices as in T2 (the `FuelStationComboboxTest` implicit-nullable deprecation and the Dusk "not configured" skip).
- Cleanup: the temporary Puppeteer script, the xlsx inspector, the seed/cleanup scripts and the throwaway `verify@transport.test` user and its seeded dev rows were all removed after the run. Note the dev database (`transport`, not `transport_testing`) had its pre-existing `logsheet_imports`/`logsheets` rows cleared by the verification fixture; the test database is unaffected.
