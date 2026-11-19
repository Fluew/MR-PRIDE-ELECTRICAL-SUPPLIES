# MRP ElectroDesk

Web-based sales, inventory and procurement management system for **MR-PRIDE Electrical Supplies**.

Customers browse the public catalogue and request quotations. Administrators and staff process
those quotations into sales orders, manage inventory, purchase from suppliers, record deliveries
and payments, and use data-driven decision support: supplier evaluation and product
prioritization (AHP weighted scoring) and demand forecasting (Simple Moving Average).

## Technology stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.3+, Laravel 13, Laravel Fortify (authentication) |
| Frontend | Vue 3 (TypeScript), Inertia.js v3, Tailwind CSS v4, Vite 8 |
| Routing bridge | Laravel Wayfinder (typed route functions generated for the frontend) |
| Database | MySQL / MariaDB (XAMPP locally, MySQL on Railway) |
| Documents | Dompdf (PDF), PhpSpreadsheet (Excel/CSV), Tesseract + Poppler (OCR) |
| Integrations | Resend (email), Frankfurter (USD to PHP exchange rate) |
| Testing | Pest (PHP), Vitest (Vue) |
| Hosting | Railway (staging and production) |

## Main modules

| Module | Controller | Business logic |
|---|---|---|
| Authentication, roles and trusted devices | `Auth/*`, `UserController` | `app/Providers/FortifyServiceProvider.php`, `app/Http/Middleware/`, `Services/DeviceEnrollmentService.php` |
| Quotation requests and OCR of product lists | `PublicQuotationController`, `PublicProductListController`, `QuotationController` | `Services/Quotations/`, `Services/MaterialList/` |
| Inventory | `InventoryController` | `Services/Inventory/` |
| Procurement and purchase orders | `PurchaseRequestController`, `ProcurementWorkflowController` | `Services/Procurement/` |
| Inbound and outbound deliveries | `InboundDeliveryController`, `DeliveryController` | `Services/Procurement/InboundReceiving.php`, `Services/Delivery/` |
| Supplier evaluation (AHP) | `SupplierController` | `Services/Suppliers/SupplierEvaluator.php` |
| Product prioritization and reorder advice (AHP) | `InventoryController` | `Services/Inventory/ProductPrioritizer.php` |
| Demand forecasting (SMA) | `ForecastController` | `Services/Forecast/SmaForecaster.php` |
| Sales, invoices and payments | `SaleController` | `Services/Sales/` |
| Reports and analytics | `ReportController`, `DashboardController` | `Services/Reports/` |
| Data import (Excel, CSV, PDF) | `ImportController` | `Services/Import/` |
| Email notifications | — | `Services/ResendService.php`, `Services/Notifications/`, `Services/CustomerEmails.php` |
| Currency display | `Settings/CurrencyController` | `Services/ExchangeRates.php`, `Support/Currency.php` |
| Maintenance, backup and recycle bin | `Settings/MaintenanceController`, `RecycleBinController` | `Services/MaintenanceService.php`, `Console/Commands/DatabaseBackup.php` |

Controllers are in `app/Http/Controllers/`; services are in `app/Services/`.

## Where the algorithms are

| Algorithm | File | Notes |
|---|---|---|
| AHP supplier evaluation | `app/Services/Suppliers/SupplierEvaluator.php` | Delivery Performance 40%, Price Competitiveness 35%, Product Quality 25%. Score = sum of (criterion score × weight). |
| AHP product prioritization | `app/Services/Inventory/ProductPrioritizer.php` | Demand 40%, Stock 30%, Movement 20%, Supplier Availability 10%. Also produces the reorder recommendation. |
| SMA demand forecasting | `app/Services/Forecast/SmaForecaster.php` | Average of the previous 3, 6 or 12 months of demand. |
| Weights and classification bands | `config/evaluation.php` | Fixed in configuration; not editable from the interface. |

Scores are calculated only from recorded transactions. A criterion without enough data is
reported as "insufficient data" and is never treated as zero. Recalculation runs through
`app/Jobs/RecalculateEvaluations.php` and `app/Listeners/RefreshEvaluations.php`.

## Directory structure

```text
MR-Electrical-Supplies/
├── app/
│   ├── Actions/            Fortify and team actions
│   ├── Console/Commands/   db:backup
│   ├── Enums/              Status values (quotation, sales order, payment, delivery)
│   ├── Events/, Listeners/ Procurement events and their handlers
│   ├── Http/
│   │   ├── Controllers/    One controller per module
│   │   ├── Middleware/     Role, trusted-device and password-expiry checks
│   │   ├── Requests/       Form validation
│   │   └── Responses/      Fortify login and logout responses
│   ├── Jobs/               Queued work (imports, evaluations, emails)
│   ├── Models/             Eloquent models
│   ├── Notifications/      In-app and email notifications
│   ├── Policies/, Rules/   Authorization and validation rules
│   ├── Providers/          Application and Fortify setup
│   ├── Services/           Business logic, grouped by module
│   └── Support/            Small helpers (currency, CSV, dates)
├── bootstrap/              Application bootstrap, middleware aliases
├── config/                 Configuration (evaluation.php holds the AHP weights)
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── public/                 Web root, images
├── resources/
│   ├── css/
│   ├── js/
│   │   ├── components/     Shared components, grouped by module; ui/ is the base UI kit
│   │   ├── composables/
│   │   ├── layouts/        App, auth, public and settings layouts
│   │   ├── pages/          One folder per module (Inertia pages)
│   │   └── types/
│   └── views/              Blade shell, PDF documents, exports
├── routes/                 web.php, settings.php, console.php
├── storage/                Uploads, backups, logs (not in Git)
├── tests/                  Pest (Feature, Unit, Capstone) and Vitest (js)
├── railway*.json, railpack.json   Railway deployment
└── composer.json, package.json, vite.config.ts
```

## Local installation (XAMPP)

Requirements: XAMPP (Apache and MySQL/MariaDB), PHP 8.3 or newer with the `gd`, `zip` and
`pdo_mysql` extensions, Composer 2 and Node.js 22 or newer. OCR of uploaded product lists also
needs Tesseract and Poppler.

1. Put the project in the XAMPP `htdocs` folder and start **Apache** and **MySQL** from the
   XAMPP Control Panel.
2. Create an empty database in phpMyAdmin, for example `mr_pride`.
3. Install dependencies and create the environment file:

   ```bash
   composer install
   npm install
   copy .env.example .env
   php artisan key:generate
   ```

4. Set the database values in `.env` (next section), then create the tables:

   ```bash
   php artisan migrate
   ```

5. Start the application:

   ```bash
   composer run dev
   ```

   This runs the PHP server (http://localhost:8000), the queue listener and the Vite dev server
   together.

Every login must come from a trusted device. To trust your own browser, run
`php artisan device:enroll-code --user=<admin email>` and open the link it prints.

## Database configuration

Set these in `.env`. The file is not committed; `.env.example` is the template.

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mr_pride
DB_USERNAME=<your MySQL user>
DB_PASSWORD=<your MySQL password>
```

Email (`RESEND_*`), OCR (`TESSERACT_PATH`, `PDFTOPPM_PATH`) and backup (`MYSQLDUMP_PATH`)
settings are described in `.env.example`.

`php artisan db:seed` creates demonstration accounts and sample data. Use it only on a new
local database, never on staging or production.

## Development commands

| Command | Purpose |
|---|---|
| `composer run dev` | PHP server, queue listener and Vite dev server |
| `npm run build` | Production build of the frontend |
| `php artisan route:list` | List every route |
| `php artisan migrate` | Apply new migrations |
| `php artisan db:backup` | Back up the database to `storage/app/backups` |
| `php artisan queue:work` | Process queued jobs |
| `php artisan schedule:work` | Run scheduled tasks (daily backup, cleanup) |
| `composer lint` / `npm run lint` | Format and lint PHP / TypeScript and Vue |
| `npm run types:check` | TypeScript type check |

## Deployment (Railway)

Railway builds the repository with Railpack; no Dockerfile is used. Staging deploys from the
`staging` branch, and production deploys from its own branch in a separate Railway environment.

| File | Service | Role |
|---|---|---|
| `railway.json` | web | Serves the application. Runs `php artisan migrate --force` before each deploy and checks `/up`. |
| `railway.worker.json` | worker | `php artisan queue:work` (imports, evaluation recalculation, queued emails) |
| `railway.scheduler.json` | scheduler | `php artisan schedule:work` (daily database backup, cleanup) |
| `railpack.json` | all | Installs Tesseract, Poppler and the MySQL client |

Environment variables (application key, database URL, Resend key) are set in Railway and are
never stored in the repository. Uploaded files and backups are kept on a volume mounted at
`/app/storage/app`.

## Testing

```bash
php artisan test        # Pest: Feature, Unit and Capstone suites
npm test                # Vitest: Vue component tests
```

`php artisan test` uses an in-memory SQLite database (`phpunit.xml`), so it never touches the
local, staging or production data. Run `npm run build` first; tests that render pages need the
built assets.

The capstone test cases are in `tests/Unit/Capstone/` (unit) and `tests/Feature/Capstone/`
(integration). The same cases can be run against MariaDB with a dedicated testing database:

```bash
php vendor/bin/pest -c phpunit.mysql.xml
```

This configuration connects only to the local database `mr_pride_testing`; create that empty
database first.
