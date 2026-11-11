# 4.4.1 Unit Testing (revised — MySQL/MariaDB)

> **Notes for the proponents (delete before submission)**
>
> - This revised section replaces the earlier 100-case SQLite version of 4.4.1 (Reports 1 and 3). It has exactly 25 test cases, all executed on MariaDB.
> - All results below are from runs on 11 November 2026 on the local machine. The suite was run three times in a row with the same result each time. Raw result file: [evidence/unit-mysql-junit.xml](evidence/unit-mysql-junit.xml).
> - Two test cases failed in the initial run. Table 4.3 keeps those initial results as found. The two defects were corrected afterwards, at the proponents' request, and all 25 cases passed on retest (Table 4.3-A; raw file [evidence/unit-mysql-retest-junit.xml](evidence/unit-mysql-retest-junit.xml)).
> - Run again with `php vendor/bin/pest -c phpunit.mysql.xml --testsuite=Unit`.

---

Unit testing was conducted to verify that the primary functions, validations, calculations, and business rules of each module of MRP ElectroDesk work correctly before the modules were integrated. The proponents applied black-box functional testing, selecting test data through equivalence partitioning (valid and invalid inputs) and boundary value analysis (the smallest and largest accepted values and the first values outside them). Computed results such as quotation totals, supplier scores, remaining balances, stock quantities, and demand forecasts were compared with values calculated by hand.

The tests were written in Pest 4.7.8, which runs on PHPUnit, using the testing tools of Laravel 13.38.0 and PHP 8.3.36. They were executed in the local development environment on MariaDB 10.4.32, the MySQL-compatible database server of XAMPP, using a dedicated testing database named `mr_pride_testing`. The testing configuration connects only to this database. Before any test was run, the proponents verified that the test process was connected to `mr_pride_testing` and that it stops, without running a query, when any other database or server is configured. The development database (`mr_pride`) was not used: the row counts of its 48 tables were identical before and after testing. The email service, the exchange rate service, and all other external connections were disabled during testing.

**Table 4.3. Unit Test Cases of MRP ElectroDesk**

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| | **Authentication and Access Administration** | | | | | |
| UT-01 | Login | Sign-in with valid and invalid credentials | Wrong password; unregistered email; blank email and password; then the registered email and correct password | The three invalid attempts are refused; the valid attempt signs the user in | The three invalid attempts were refused with error messages; the valid attempt signed the user in and redirected to the dashboard | Passed |
| UT-02 | Role permissions and device authorization | Access by role and by registered device | Administrator, Sales, Reports, and customer accounts against module lists; valid, forged, and revoked device credentials; login without a registered device | Each role opens only its own module; only a registered, approved device is accepted | The administrator opened all modules; staff opened only their own; forged and revoked devices were not recognized; login without a device was blocked (HTTP 404) | Passed |
| | **Quotation Management** | | | | | |
| UT-03 | Quotation totals | Computation of subtotal, discount, tax, and grand total | 4 × 250.00, discount 100.00, tax 12%; 3 × 33.33, no discount or tax | 1,000.00, 100.00, 108.00, 1,008.00; and 99.99 | 1,000.00, 100.00, 108.00, 1,008.00; and 99.99 | Passed |
| UT-04 | Quotation form | Validation of quantities, prices, and required fields | Quantities 0, −1, 1.5, "abc", blank; price −1; no customer name; tax 101%; no items; blank public request form; then quantity 1 | All invalid entries are refused and nothing is saved; quantity 1 is accepted | All ten invalid entries were refused; no quotation was saved; quantity 1 was saved | Passed |
| UT-05 | Quotation status | Allowed status changes | Draft→Sent, Draft→Accepted, Sent→Accepted, Accepted→any; manual status "accepted", "sent", "not-a-status", blank; then "rejected" | Only defined changes are allowed; statuses that staff may not set are refused | Draft→Accepted was not allowed; Accepted had no further status; the four manual values were refused; "rejected" was saved | Passed |
| | **Inventory Management** | | | | | |
| UT-06 | Product record | Product validation and stock status | Blank form; price −1; stock −1; stock 1.5; then stock 0, 10, and 11 with reorder level 10 | Invalid entries are refused; statuses are Out of Stock, Low Stock, and In Stock | Invalid entries were refused; statuses were Out of Stock, Low Stock, and In Stock; SKUs BRK-000001 to BRK-000003 were generated | Passed |
| UT-07 | Stock-in | Stock-in quantity validation and stock computation | Quantities 0, −5, 2.5, "ten", blank; then 5 on a stock of 10 | Invalid quantities are refused; stock becomes 15 | Invalid quantities were refused; stock became 15 with a movement record of 10 → 15 | Passed |
| UT-08 | Stock-out | Stock-out against available stock | Stock 10: quantities 11, 0, −3; with 4 units reserved: quantity 7, then 6 | Quantities above the available stock are refused; stock never becomes negative | 11, 0, −3, and 7 were refused ("Available quantity: 6"); 6 was accepted; stock became 4 with 0 available | Passed |
| UT-09 | Reorder recommendation | Low-stock and reorder computation | Stock 5 with a forecast of 150 and reorder quantity 100; stock 500; stock at the reorder level without history; stock above it | Recommend 145; none; recommend the reorder quantity (30); none | 145; none; 30; none | Passed |
| | **Procurement** | | | | | |
| UT-10 | Purchase request | Quantity validation and duplicate request | Quantities 0, −3, 2.5, blank, 1,000,001; no justification; unlisted unit "bucket"; then 1; then a second request for the same product | Invalid entries are refused; quantity 1 is saved; the duplicate request is refused | All seven invalid entries were refused; quantity 1 was saved as Pending Approval; the second request was refused; stock was unchanged | Passed |
| UT-11 | Purchase order | Purchase order validation and cost computation | Approved request for 40 units; approval repeated; unknown supplier; quantity 0; unit cost 0 and −1; then unit cost 12.50 | One purchase order is created; invalid entries are refused; total cost is 500.00 | One draft purchase order was created; the repeated approval and the four invalid entries were refused; total cost was 500.00 | Passed |
| UT-12 | Imported purchase order | Field mapping and quantity validation of an imported file | One valid row (100 × 450.00); rows with quantities 0, −5, and 2.5 | The valid row is saved with all fields and a total of 45,000.00; the three invalid rows are refused | The valid row was saved correctly (45,000.00). Quantity 0 was refused, but **−5 and 2.5 were accepted as valid rows** | **Failed** |
| | **Supplier Evaluation** | | | | | |
| UT-13 | Delivery timeliness | On-time rate and quantity fulfillment | Two deliveries, one on time; 97 of 100 units accepted; a delivery on the promised date; a delivery one day late | (50 × 0.60) + (97 × 0.40) = 68.80, weighted 27.52; on-time rates of 100% and 0% | 68.80, weighted 27.52; on-time rates of 100% and 0% | Passed |
| UT-14 | Price competitiveness | Comparison with the lowest purchase cost | Unit costs of 1,100.00 and 1,000.00 for the same product; a supplier with no comparable product | 90.91 (weighted 31.82) and 100.00; no price score without a comparison | 90.91 (weighted 31.82) and 100.00; no price score, final score, or rank for the third supplier | Passed |
| UT-15 | Product quality and final score | Quality rate, weighted final score, classification, and rank | 97 accepted and 3 rejected; criterion scores 68.80, 90.91, 97.00; a second supplier with perfect records; a supplier with no records | Quality 97.00 (weighted 24.25); final score 83.59 "Very Good", rank 2; second supplier 100.00 "Excellent", rank 1; no score without records | Quality 97.00 (24.25); 27.52 + 31.82 + 24.25 = 83.59 "Very Good", rank 2; 100.00 "Excellent", rank 1; no score for the supplier without records | Passed |
| | **Sales and Payment Recording** | | | | | |
| UT-16 | Sales order | Creation of a sales order from a quotation | No quotation; unknown quotation; Draft, Sent, and Rejected quotations; then an Accepted quotation, submitted twice | Only the accepted quotation creates one sales order with the same total; the second submission is refused | The five invalid requests were refused; one sales order of 224.00 was created (Pending, balance 224.00); the second submission was refused; stock was unchanged | Passed |
| UT-17 | Payment record | Validation of payment amount, method, date, and proof | Amounts 0, −50, "abc", blank, 0.001; method "Cash"; a date of tomorrow; an executable file as proof; then 0.01 | Invalid entries are refused; 0.01 is recorded and not yet counted as paid | All eight invalid entries were refused; 0.01 was recorded as Submitted; amount paid remained 0.00 | Passed |
| UT-18 | Payment verification | Remaining balance, over-payment, and duplicate transaction | Order of 224.00: payment of 100.00; then 124.01; the same bank reference again; then 124.00 | Balance 124.00; over-payment and duplicate reference are refused; balance 0.00 after the final payment | Balance 124.00 (Partially Paid); 124.01 and both duplicate references were refused; balance 0.00, order Paid and Confirmed | Passed |
| | **Delivery Management** | | | | | |
| UT-19 | Inbound delivery | Inbound quantity validation and stock adjustment | Ordered 20: 7 accepted + 2 rejected of 10 received; 21 received; −1 accepted; rejected units without a reason; then 20 received, 17 accepted, 3 rejected (stock 25) | Inconsistent entries are refused; stock becomes 42 | The four inconsistent entries were refused and nothing was saved; stock became 42 with a movement record of 25 → 42 | Passed |
| UT-20 | Outbound delivery | Outbound release and received quantity validation | Ordered 5: release of 6, 0, and −1; dispatch before the release is ready; received 6 and 0 | All are refused; no stock leaves inventory | All six were refused; stock remained 25 with no movement record | Passed |
| UT-21 | Outbound delivery | Stock adjustment on delivery | Stock 25; 5 released, 3 received; stock deduction repeated | Stock becomes 22, deducted once; 2 units remain reserved | Stock became 22 with one movement record of 25 → 22; 3 delivered, 2 reserved | Passed |
| | **Demand Forecasting** | | | | | |
| UT-22 | Simple Moving Average | Forecast accuracy, rounding, and zero-demand periods | Monthly demand (10, 20, 30); (10, 10, 11); (1, 2, 2); (30, 0, 0); a range of exactly three months | 20; 10; 2; 10 then 0; no forecast for the three-month range | 20; 10; 2; 10 then 0; no forecast | Passed |
| UT-23 | Forecast per product | Missing historical data, forecasting period, and demand source | Product without records; six months of demand; a confirmed order of 75 with pending and cancelled orders of 40; periods 3, 6, 12, "6", 5, 0, −3, blank, "abc" | No forecast with a stated reason; 55 per month (165) for three months; 38 per month (225) for six months; only 3, 6, and 12 are accepted | No forecast, with the reason shown; 55 (165); 38 (225); invalid periods were replaced by the default of 3 | Passed |
| | **Reporting and Analytics** | | | | | |
| UT-24 | Report figures | Date-range filtering and aggregation | Six sales of different dates and statuses; range of October 1 to 10; three products; records of another company | Range: 2 sales, 600.00; all time: 4 sales, 1,500.00; inventory value 650.00; one product each in stock, low, and out of stock | 2 sales, 600.00; 4 sales, 1,500.00; this month 1,400.00; inventory value 650.00; 1 / 1 / 1; the other company's records were excluded | Passed |
| UT-25 | Report date range | Date-range validation | Start date "not-a-date" | The request is handled without a server error | **A server error (HTTP 500) was returned** | **Failed** |

**Summary.** Twenty-five (25) unit test cases were executed across the nine modules of MRP ElectroDesk. Twenty-three (23) passed, two (2) failed, and none were blocked. The same result was obtained in three consecutive runs.

| Module | Executed | Passed | Failed | Blocked |
|---|---:|---:|---:|---:|
| Authentication and Access Administration | 2 | 2 | 0 | 0 |
| Quotation Management | 3 | 3 | 0 | 0 |
| Inventory Management | 4 | 4 | 0 | 0 |
| Procurement | 3 | 2 | 1 | 0 |
| Supplier Evaluation | 3 | 3 | 0 | 0 |
| Sales and Payment Recording | 3 | 3 | 0 | 0 |
| Delivery Management | 3 | 3 | 0 | 0 |
| Demand Forecasting | 2 | 2 | 0 | 0 |
| Reporting and Analytics | 2 | 1 | 1 | 0 |
| **Total** | **25** | **23** | **2** | **0** |

The two failed test cases identified the defects described below. The proponents recorded them as found, then corrected them and retested.

**Failed test cases and recommended corrections**

| Field | UT-12 | UT-25 |
|---|---|---|
| Affected module | Procurement — purchase order file (CSV) import | Reporting and Analytics — report date range |
| Test input | Rows with quantities −5 and 2.5 (unit cost 10.00) | `from_date=not-a-date` with the custom date range |
| Expected result | Both rows are refused, as the quantity must be a whole number of at least 1 | The request is handled without a server error |
| Actual result | Both rows were accepted as valid. In an earlier check of the same import, −5 was read as 5 and 2.5 as 25 | HTTP 500 |
| Error message | None; the rows were accepted without a warning | `Carbon\Exceptions\InvalidFormatException: Could not parse 'not-a-date'` |
| Probable cause | `PurchaseRequestController.php` (line 410) removes every non-digit character from the quantity before checking it, so the minus sign and the decimal point are lost | `ReportController::index` passes the date inputs to `Carbon::parse()` without validating them |
| Severity | High — incorrect quantities and costs can be stored | Low to Medium — the page's date fields send valid dates; a typed address is needed to cause it |
| Recommended correction | Validate the original value as a whole number of at least 1 and report other values as invalid rows | Validate `from_date` and `to_date` with the `date` rule before use |
| Correction applied | The quantity is read as written and must be a whole number of at least 1; other values are reported as invalid rows | An unreadable date in the date range is treated as not given |
| Retest status | **Passed on retest** (11 November 2026) | **Passed on retest** (11 November 2026) |

**Retest after correction.** After the two defects were corrected, the proponents executed all 25 unit test cases again on the same MariaDB testing database, so that the corrections could also be checked for side effects on the other cases. All twenty-five (25) test cases passed, with none failed and none blocked, in three consecutive runs.

**Table 4.3-A. Retest Results of the Failed Unit Test Cases**

| ID | Function / Module | Test Data / Input | Expected Result | Initial Result | Retest Result | Status after Retest |
|---|---|---|---|---|---|---|
| UT-12 | Imported purchase order | Rows with quantities 0, −5, and 2.5 | The three rows are refused | −5 and 2.5 were accepted as valid rows | All three rows were refused; the valid row was still saved correctly (45,000.00) | Passed |
| UT-25 | Report date range | Start date "not-a-date" | The request is handled without a server error | HTTP 500 | No server error was returned; the unreadable date was treated as not given | Passed |

| Run | Executed | Passed | Failed | Blocked |
|---|---:|---:|---:|---:|
| Initial run | 25 | 23 | 2 | 0 |
| Retest after correction | 25 | 25 | 0 | 0 |

---

## Testing record (for the proponents; not part of the chapter text)

**Environment**

| Item | Value |
|---|---|
| Database server | MariaDB 10.4.32 (XAMPP), `127.0.0.1:3306` |
| Testing database | `mr_pride_testing` (`utf8mb4`), created empty for this purpose; 48 tables built by the application's own migrations |
| Configuration | `phpunit.mysql.xml` — every database setting forced; the project's `phpunit.xml` (SQLite) is unchanged |
| Test code | `tests/CapstoneMysql/Unit/UnitTestingMysqlTest.php`, `tests/Capstone/MysqlTestCase.php`, `tests/Capstone/mysql_helpers.php`, `tests/Capstone/fixtures.php` |
| Data handling | Each test runs in a transaction that is rolled back; the testing database holds no rows after a run |
| Fixtures | The project's `User` and `Team` factories; other records are created through the application's models and services, because the project has no factories for them |

**Database isolation checks performed**

| Check | Result |
|---|---|
| `mr_pride_testing` did not exist before; created as a new empty database | Confirmed |
| Probe before any migration: database the test process is connected to | `mr_pride_testing` on `127.0.0.1`, 0 tables |
| Run with the default configuration (SQLite) | Stopped before any test: "the dedicated testing database is not the only possible target" |
| Run with `DB_DATABASE=mr_pride` set in the shell | Stopped before the application started |
| Run with a different `DB_HOST` set in the shell | Stopped before the application started |
| `mr_pride` row counts, 48 tables, before and after the test runs | Identical |
| `mr_pride` tables and newest table creation time, before and after | 48 and 2026-11-10 13:53:31 in both |
| Test records in `mr_pride` | None |

**Two things found while preparing the tests**

1. *Guard correction.* The first version of the isolation guard read the settings with `getenv()` only. A deliberate negative test (exporting `DB_DATABASE=mr_pride` in the shell) showed that Laravel would still have resolved `mr_pride` in that situation. That probe had no schema-changing step and ran a single read-only `SELECT DATABASE()`; nothing was written. The guard now checks every source Laravel reads, and the connection is checked again inside Laravel before any migration can run. Both were re-tested (table above).
2. *Test correction.* In the first MySQL run, UT-20 and UT-21 failed because the test passed received quantities keyed by the release line instead of the sales order line. On SQLite the two identifiers happened to be equal, so the same mistake in the earlier UT-78 and UT-79 went unnoticed. The tests were corrected; the application was not changed. This is a difference that only testing on MySQL revealed.

**Scope note.** The earlier 100-case SQLite run found four further defects that are outside these 25 cases (duplicate purchase order import, unreadable date in Demand Forecasting, stock adjustment without the remarks field, and the always-zero "pending quotations" figure). They were corrected at the same time and all 100 of those cases now pass; see the retest section of [01-unit-testing-report.md](01-unit-testing-report.md).

**Regression check after the corrections (11 November 2026).** Full project suite on SQLite: 1,021 tests, 1,018 passed, 3 skipped, 0 failed. Integration suite on MariaDB: 24 of 24 passed. `mr_pride` row counts identical before and after.
