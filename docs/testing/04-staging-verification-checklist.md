# Staging Verification Checklist (Railway)

**Status: prepared only. Railway staging has not been accessed, changed or deployed to.**
Staging verification starts only after the proponents give explicit approval and the open items in section 1 are answered.

---

## 1. Items that must be confirmed before staging is touched

What could be established from the repository alone, and what could not:

| # | Item to confirm | What the repository shows | Confirmed? |
|---|---|---|---|
| 1 | Staging branch | A `staging` branch exists locally and on `origin`. `RAILWAY_DEPLOYMENT.md` names the deployed branch `pre-testing`, **which does not exist**. `dev`, `staging` and `main` are the same commit (`204cec2`) | ☐ Proponents to state which branch the staging service deploys |
| 2 | Deployment configuration | `railway.json` (web: `php artisan migrate --force` before each deploy, health check `/up`), `railway.worker.json` (queue), `railway.scheduler.json` (schedule), `railpack.json` (Tesseract, Poppler, MySQL client) | ☐ Confirm the staging services use these files |
| 3 | A separate staging environment exists | **Not shown.** `RAILWAY_DEPLOYMENT.md` describes one environment with `APP_ENV=production` and `APP_URL=https://mr-pride.com` | ☐ Confirm there is a Railway environment or project for staging, separate from production |
| 4 | Staging database is separate from production | **Cannot be verified from the repository.** The variables use `DB_URL=${{MySQL.MYSQL_URL}}`, which points to whichever MySQL service is in the same Railway environment | ☐ Confirm the staging MySQL service is not the production one (different host and database) |
| 5 | Staging credentials are not production credentials | Cannot be verified from the repository | ☐ Different `APP_KEY`, database password, administrator accounts and recovery key |
| 6 | External integrations use safe settings | Cannot be verified from the repository | ☐ See section 3 |
| 7 | Nobody else is using staging during the run | — | ☐ |
| 8 | A way to restore staging afterwards | — | ☐ Database backup taken before the run, or agreement that test records may stay |

## 2. Rules for the staging run

1. **The automated test suite must never be run against staging.** It uses `RefreshDatabase`, which rebuilds the schema of the database it is connected to. Staging verification is done by using the deployed application, with test records created for the purpose.
2. No `migrate:fresh`, `db:wipe`, `db:seed` or other destructive command. (`db:seed` also creates the demo administrator `admin@example.com` / `admin123`.)
3. No deployment, push, merge or variable change by the tester.
4. Every test record is given a recognisable name (for example customer "TEST-IT-05 Metro Builders") so it can be found and removed.
5. Emails go only to an address owned by the proponents.
6. Results are written in the staging column of the table in Report 2, section 4, from what is observed in staging. A case that was not run is recorded as "Not executed" or "Not applicable", never as passed.

## 3. External integrations — safe configuration

| Integration | Local test run | Check in staging before the run |
|---|---|---|
| Resend (email) | Not called; the application's test substitute | ☐ Staging uses its own Resend API key (not production's). ☐ With the test sender `onboarding@resend.dev`, mail reaches only the Resend account owner — acceptable for testing. ☐ If a verified domain is used, send only to the proponents' addresses |
| Frankfurter (exchange rate) | Faked response | ☐ Read-only public service; no key. ☐ Outbound HTTPS allowed from Railway |
| Tesseract / Poppler (OCR) | Mocked; Tesseract is not installed locally | ☐ Installed by `railpack.json`. Check with the command in `RAILWAY_DEPLOYMENT.md` ("OCR check") |
| Mail driver (`MAIL_MAILER`) | `array` | ☐ `log` unless SMTP is intended |

## 4. Differences between the local test environment and staging

| Aspect | Local test run | Staging (per `RAILWAY_DEPLOYMENT.md`) | Why it matters |
|---|---|---|---|
| Database | SQLite, in memory | MySQL | Date comparison (finding DB-3), row locking, strict mode |
| Data | Empty database, built by each test | Existing staging data | Report and forecast figures will include other records |
| PHP / web server | PHP 8.3.36 CLI, no web server | Railpack image behind Railway's HTTPS proxy | Secure cookies, HTTPS links |
| Queue | `sync` (runs at once) | `database` queue with a worker service | Imports, evaluation refresh and queued emails are delayed until the worker runs |
| Scheduler | Not running | `schedule:work` service | Backups, invitation and audit-log pruning |
| File storage | Faked disk | Volume at `/app/storage/app` on the web service | The worker cannot read uploads unless `QUEUE_CONNECTION=sync` (noted in the deployment guide) |
| Email | Substitute | Resend API | Real messages are sent |
| Exchange rate | Faked | Live Frankfurter service | Rate value and availability vary |
| OCR | Mocked | Tesseract and Poppler | Real reading accuracy |
| Device verification | Trusted device created by the test | A browser must be enrolled first (`device:enroll-code`) | Tester needs an enrolled staging browser |
| Front-end assets | Vite development server | Built by Railway on deploy | The stale local build (Report 1, section 7, item 4) does not apply to a fresh build |
| Clock | Fixed where needed | Real time, UTC | Date-dependent results use the real date |

## 5. Preparation in staging (after approval)

- ☐ Record the staging URL, branch and deployed commit.
- ☐ Health check `/up` and `/health` respond.
- ☐ Enrol the tester's browser as a trusted device.
- ☐ Test accounts exist: one administrator and one staff account each for Quotations, Sales, Inventory, Procurement, Deliveries, Demand Forecasting, Suppliers and Reports (or one staff account with those modules).
- ☐ Test data exists: one supplier, two products with known stock, one product below its reorder level.
- ☐ Note the stock of the test products before starting.

## 6. Scenarios to execute in staging

| ID | Steps (through the staging application) | What to observe | Result |
|---|---|---|---|
| IT-01 | Sign in as administrator | Dashboard opens; every module opens | ☐ |
| IT-02 | Sign in as sales staff; type the address of another module | Lands on Sales; other module shows the 403 page | ☐ |
| IT-03 | Sign out; press Back; open a workspace address | Login page; no workspace page | ☐ |
| IT-04 | Submit the public quotation form; price and submit as staff; approve as administrator | Request listed as Draft; approval recorded; stock unchanged | ☐ |
| IT-05 | Send the quotation; record acceptance; create the sales order; try again | One order with the same totals; second attempt refused | ☐ |
| IT-06 | Record a rejection on another quotation; try to create an order | Refused | ☐ |
| IT-07 | Record a payment with proof; verify it | Receipt and invoice issued; order Confirmed; balance 0.00; stock reserved | ☐ |
| IT-08 | Verify a partial payment; try to verify more than the balance | Balance shown; over-payment refused; one receipt | ☐ |
| IT-09 | Prepare, ready, assign, dispatch and deliver the order with a photo | Stock reduced once by the delivered quantity; order Completed | ☐ |
| IT-10 | Confirm two orders for more than the stock; try a manual stock-out | Second order partly reserved; stock-out refused | ☐ |
| IT-11 | Open Demand Forecasting for the delivered product | This month's demand includes the delivered quantity | ☐ |
| IT-12 | Open Reports | Sales, revenue and stock value changed by the test transactions | ☐ |
| IT-13 | Send an approved quotation to the test address | Email received with the PDF; status Sent; email log entry | ☐ |
| IT-14 | — | Not applicable unless a mail failure can be simulated safely | ☐ N/A |
| IT-15 | Raise a purchase request from a reorder recommendation | Request Pending in Procurement with stock and forecast figures | ☐ |
| IT-16 | Approve; confirm supplier and cost; send; record a partial delivery | Draft order created once; order Partially Received | ☐ |
| IT-17 | Inspect the delivery with some rejected units; try to post again | Only accepted units added, once | ☐ |
| IT-18 | Record an inbound delivery with an inconsistent line | Refused; no stock change | ☐ |
| IT-19 | Open the supplier's evaluation **on the same day** as the delivery | Delivery and quality scores include today's delivery (checks finding DB-3 on MySQL) | ☐ |
| IT-20 | Switch the display currency to PHP; open Procurement | Amounts shown in PHP at the current rate; stored USD amounts unchanged after switching back | ☐ |
| IT-21 | — | Not applicable (the service cannot be made to fail safely) | ☐ N/A |
| IT-22 | Submit a quotation request with a real photo of a product list | Rows read by OCR shown to staff for review | ☐ |
| IT-23 | Import a price list; correct a flagged row; confirm; confirm again | New products created once; existing products unchanged | ☐ |
| IT-24 | Upload a file and cancel it | No product created | ☐ |

## 7. After the run

- ☐ Fill the staging column in Report 2, section 4, with the observed results and remarks.
- ☐ Record every failure as a defect in the Report 1 format (test case ID, module, input, expected, actual, error message, probable cause, severity, recommended correction, retest status).
- ☐ Remove or archive the test records, or note that they remain.
- ☐ Record the date, tester, staging URL and deployed commit.
