# 4.4.2 Integration Testing (revised — MySQL/MariaDB)

> **Notes for the proponents (delete before submission)**
>
> - This revised section replaces the earlier SQLite version of 4.4.2 (Reports 2 and 3). The same 24 scenarios were executed on MariaDB.
> - All results are from runs on 11 November 2026 on the local machine. The suite was run three times in a row with the same result each time. Raw result file: [evidence/integration-mysql-junit.xml](evidence/integration-mysql-junit.xml).
> - Run again with `php vendor/bin/pest -c phpunit.mysql.xml --testsuite=Integration`.
> - **Staging has not been accessed.** The staging column of Table 4.5 must be filled only from results observed in staging, after approval (see [04-staging-verification-checklist.md](04-staging-verification-checklist.md)).
> - These runs were made after the Deliveries access correction of the same day (see the testing record at the end).

---

Integration testing was conducted to verify that the modules of MRP ElectroDesk exchange data correctly and keep the database consistent when they work together. The proponents used an incremental integration approach: the modules were first tested individually during unit testing, and were then combined one at a time in the order in which data moves through the business process, so that a failure could be traced to the most recently added module. Each test case used the actual routes, controllers, services, and models of the system.

The tests were written in Pest 4.7.8, which runs on PHPUnit, using the testing tools of Laravel 13.38.0 and PHP 8.3.36. They were executed in the local development environment on MariaDB 10.4.32, the MySQL-compatible database server of XAMPP, using the dedicated testing database `mr_pride_testing`. The testing configuration connects only to this database and stops, without running a query, when any other database or server is configured. The development database (`mr_pride`) was not used: the row counts of its 48 tables were identical before and after testing. The external services were replaced by controlled substitutes — the email service (Resend), the exchange rate service (Frankfurter), and the optical character recognition engine (Tesseract) — and the tests were set to fail if any real external request was attempted.

**Table 4.4. Integration Test Cases of MRP ElectroDesk (Local, MariaDB)**

| ID | Modules Integrated | Test Scenario | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|
| | **Authentication → Role-Based Dashboard** | | | | |
| IT-01 | Authentication → Administrator dashboard → all modules | The administrator signs in on a registered device and opens the dashboard and each module | The administrator is signed in, sent to a dashboard, and can open all nine modules | The administrator was signed in and redirected to a dashboard; the dashboard and all nine modules opened | Passed |
| IT-02 | Authentication → Role-based access | A sales staff member signs in, then opens Sales, the dashboard, and other modules | The user is sent to Sales; the dashboard leads back to Sales; other modules are refused | The user was sent to Sales; the dashboard redirected to Sales; five other modules were refused (HTTP 403) | Passed |
| IT-03 | Authentication → Session and device control | A user signs out and reopens a page; an account is used on another account's device | The workspace is closed in both cases | The user was returned to the login page; the account on the wrong device was signed out | Passed |
| | **Quotation Request → Quotation Management** | | | | |
| IT-04 | Quotation request → Quotation Management → Inventory (cost) | A customer submits a request; staff price and submit it; the administrator approves it | The request is listed as Draft with the customer's details; staff cannot approve; the cost comes from Inventory; stock is unchanged | The request was listed with its details; staff approval was refused; the administrator's approval was recorded; total 1,000.00; line cost 150.00; stock unchanged at 50 | Passed |
| | **Quotation Acceptance → Sales Order Creation** | | | | |
| IT-05 | Quotation → Sales Order | The customer's acceptance is recorded and Sales creates the order twice | One order with the same customer, items, and totals, linked to the quotation; the second attempt is refused | One order of 336.00 (Pending, Unpaid) was created and linked in both directions; the second attempt was refused; no stock moved | Passed |
| IT-06 | Quotation → Sales Order | The customer's rejection is recorded and Sales tries to create an order | No order is created | The quotation became Rejected; the request was refused; no order exists | Passed |
| | **Sales Order → Payment Recording** | | | | |
| IT-07 | Sales Order → Payment → Receipt → Invoice → Delivery record → Stock reservation | A payment with proof is recorded and then verified | The payment counts only after verification; a receipt and an invoice are issued; the order is confirmed; stock is reserved, not removed | Amount paid stayed 0.00 until verification; then receipt 224.00, balance 0.00, invoice issued, order Confirmed and Paid, delivery record opened, 2 units reserved, stock 10 | Passed |
| IT-08 | Sales Order → Payment | A partial payment is verified; a payment above the balance is verified; another module records a payment | The balance is kept and the order stays Pending; the refused verification saves nothing; the other module is refused | Balance 124.00, Partially Paid, Pending, no invoice; the second payment stayed Submitted with one receipt on file; Inventory staff were refused (HTTP 403) | Passed |
| | **Sales Order → Outbound Delivery → Inventory Update** | | | | |
| IT-09 | Sales Order → Outbound Delivery → Inventory | A release is prepared, marked ready, assigned, dispatched, and delivered with proof; the delivery is repeated | Stock is deducted once, on delivery; the order is completed; the repeat changes nothing | Stock went from 10 to 6 with one movement record linked to the order, release, and product; the order became Completed; the repeat changed nothing | Passed |
| IT-10 | Sales Orders → Stock reservations | Two confirmed orders compete for 5 units; a manual stock-out and the dispatch of the short order are attempted | No unit is promised twice | 4 and 1 units were reserved; the stock-out and the dispatch were refused; stock stayed 5 with no movement | Passed |
| | **Sales Records → Demand Forecasting** | | | | |
| IT-11 | Sales → Outbound Delivery → Demand Forecasting | The forecast page is opened before and after a delivered sale of 7 units | No demand data before; 7 units of demand in the current month after | No data before; 7 units in the current month after, from 2 source records; stock shown as 23 | Passed |
| | **Transactions → Reporting and Analytics** | | | | |
| IT-12 | Sales + Inventory + Quotations → Reports | The report is opened before and after a delivered sale and a pending order | Sales, revenue, inventory value, and top-product figures follow the transactions; the pending order is not counted | Sales 0 → 1; revenue 448.00; inventory value 600.00 → 360.00; top product quantity 4; stock 6 | Passed |
| | **Quotation Management → Email Integration** | | | | |
| IT-13 | Quotation Management → Email service | A quotation is sent before approval, approved, and sent again | Nothing is sent before approval; the approved quotation is emailed and the email is recorded | The first attempt was refused; the quotation then became Sent and the email to the customer was logged as Sent; no real message left the machine | Passed |
| IT-14 | Quotation Management → Email service | The email service returns an error | The quotation stays approved and unsent; the failure is recorded | The quotation stayed Draft and approved; the email was logged as Failed with the reason | Passed |
| | **Inventory → Purchase Request** | | | | |
| IT-15 | Inventory → Reorder recommendation → Purchase Request → Procurement | Inventory staff raise a request from a reorder recommendation; staff try to approve it | The recommendation alone creates nothing; the request carries the stock, forecast, and recommended quantity; only an administrator approves | Recommended 145; the request was Pending with stock 5, forecast 150, recommended 145; it appeared in Procurement; both staff approvals were refused | Passed |
| | **Purchase Order → Inbound Delivery** | | | | |
| IT-16 | Purchase Request → Purchase Order → Inbound Delivery | The request is approved; goods are received on a draft order; the order is sent; 60 and then 41 units are received | One order is created from the request; a draft cannot receive goods; the partial delivery is recorded; the excess is refused | A draft order linked to the request was created; the draft receipt was refused; after sending, 60 units were recorded (order Partially Received); 41 units were refused | Passed |
| | **Inbound Delivery → Inventory Update** | | | | |
| IT-17 | Inbound Delivery → Inspection → Inventory | 60 units are received and inspected (54 accepted, 6 rejected); posting and inspection are repeated | Only the accepted units are added, once | Stock went from 5 to 59 with one movement record linked to the delivery; the second posting was refused; stock stayed 59 | Passed |
| IT-18 | Inbound Delivery (two products) → Inventory | A delivery with one valid and one invalid line is submitted; then a valid delivery, twice | Nothing is saved when one line is invalid; both products are updated together; the repeat is ignored | The first attempt saved no delivery, movement, or stock change; the second updated stock to 20 and 9 with two movements; the third was refused | Passed |
| | **Inbound Delivery → Supplier Evaluation** | | | | |
| IT-19 | Inbound Delivery → Inspection → Supplier Evaluation | The evaluation is read before and after a delivery of 100 units (94 accepted) made before the promised date, on the day of delivery | No scores before; delivery and quality scores from the records after; no final score without a price comparison | No scores before; delivery score 97.60 and quality score 94.00 after; no price score and no final score | Passed |
| | **Procurement → Exchange Rate Integration** | | | | |
| IT-20 | Procurement → Exchange Rate service | A user chooses pesos; the rate is requested twice; Procurement is opened | The rate is fetched once and reused; the page receives it; stored amounts stay in dollars | A rate of 58.25 was returned with one request sent; the page showed the peso preference and the rate; the order stayed 9.50 / 950.00 | Passed |
| IT-21 | Procurement → Exchange Rate service | The rate service returns an error | A clear "unavailable" answer; Procurement keeps working; no rate is invented | The service answered "unavailable" (HTTP 503); the page loaded without a rate; amounts were unchanged | Passed |
| | **OCR Import → Review → Record Creation** | | | | |
| IT-22 | OCR → Quotation request → Quotation Management | A customer attaches a picture of a product list (OCR simulated) | The rows read are kept for staff review with the file; nothing is priced or sent | The file was stored; 3 rows (20 pcs, 100 meters, 50 pieces) were kept as read by OCR; 3 unpriced lines; total 0.00; Draft | Passed |
| IT-23 | Document import → Review → Inventory records | A price list is uploaded; confirmation is tried with a row needing review; the row is corrected; the import is confirmed twice | Nothing is created before review is complete; new products only; no second import | The first confirmation was refused; after the correction 2 products were created with stock 0; the existing product was unchanged; the second confirmation was refused | Passed |
| IT-24 | Document import → Cancel | A file is uploaded and cancelled, then confirmed; another module opens it | No record is created; the batch is closed to other modules | No product was created; the confirmation was refused; Procurement staff were refused (HTTP 403) | Passed |

**Summary.** Twenty-four (24) integration test cases were executed, covering the fourteen workflows of MRP ElectroDesk. All twenty-four (24) passed; none failed and none were blocked. The same result was obtained in three consecutive runs.

| Workflow | Executed | Passed | Failed | Blocked |
|---|---:|---:|---:|---:|
| Authentication → Role-Based Dashboard | 3 | 3 | 0 | 0 |
| Quotation Request → Quotation Management | 1 | 1 | 0 | 0 |
| Quotation Acceptance → Sales Order Creation | 2 | 2 | 0 | 0 |
| Sales Order → Payment Recording | 2 | 2 | 0 | 0 |
| Sales Order → Outbound Delivery → Inventory Update | 2 | 2 | 0 | 0 |
| Sales Records → Demand Forecasting | 1 | 1 | 0 | 0 |
| Transactions → Reporting and Analytics | 1 | 1 | 0 | 0 |
| Quotation Management → Email Integration | 2 | 2 | 0 | 0 |
| Inventory → Purchase Request | 1 | 1 | 0 | 0 |
| Purchase Order → Inbound Delivery | 1 | 1 | 0 | 0 |
| Inbound Delivery → Inventory Update | 2 | 2 | 0 | 0 |
| Inbound Delivery → Supplier Evaluation | 1 | 1 | 0 | 0 |
| Procurement → Exchange Rate Integration | 2 | 2 | 0 | 0 |
| OCR Import → Review → Record Creation | 3 | 3 | 0 | 0 |
| **Total** | **24** | **24** | **0** | **0** |

**Database consistency.** The tests showed that related records were linked correctly across modules, such as the quotation and its sales order, the payment and its receipt, and the stock movement and its delivery. Stock quantities changed only when an inbound delivery was inspected or when a customer received the goods, and by the exact accepted or received quantity. When a step was refused, no partial data remained: a refused payment verification left no receipt, and an inbound delivery with one invalid line saved nothing. The system prevented duplicate sales orders, payments, delivery confirmations, stock postings, and import confirmations.

**Staging verification.** *[To be completed after staging verification.]* The integration scenarios have been executed only in the local environment. Verification in the Railway staging environment has not yet been performed.

**Table 4.5. Local and Staging Integration Results**

| ID | Integration Scenario | Local Result (MariaDB) | Staging Result | Remarks |
|---|---|---|---|---|
| IT-01 | Administrator sign-in and dashboard | Passed | *Not yet executed* | |
| IT-02 | Staff sign-in to the assigned module | Passed | *Not yet executed* | |
| IT-03 | Sign-out and device control | Passed | *Not yet executed* | |
| IT-04 | Quotation request → Quotation Management | Passed | *Not yet executed* | |
| IT-05 | Accepted quotation → sales order | Passed | *Not yet executed* | |
| IT-06 | Rejected quotation → no sales order | Passed | *Not yet executed* | |
| IT-07 | Payment → receipt, invoice, confirmation | Passed | *Not yet executed* | |
| IT-08 | Partial and refused payment | Passed | *Not yet executed* | |
| IT-09 | Outbound delivery → inventory | Passed | *Not yet executed* | |
| IT-10 | Reserved stock is not used twice | Passed | *Not yet executed* | |
| IT-11 | Delivered sale → Demand Forecasting | Passed | *Not yet executed* | |
| IT-12 | Transactions → Reports | Passed | *Not yet executed* | |
| IT-13 | Quotation email sent | Passed | *Not yet executed* | Local run used a substitute for the email service |
| IT-14 | Quotation email failure | Passed | *Not yet executed* | May not be safely reproducible in staging |
| IT-15 | Reorder recommendation → purchase request | Passed | *Not yet executed* | |
| IT-16 | Purchase order → inbound delivery | Passed | *Not yet executed* | |
| IT-17 | Inspection → inventory, once | Passed | *Not yet executed* | |
| IT-18 | All-or-nothing inbound delivery | Passed | *Not yet executed* | |
| IT-19 | Delivery → supplier evaluation | Passed | *Not yet executed* | |
| IT-20 | Exchange rate shown in Procurement | Passed | *Not yet executed* | Local run used a simulated rate |
| IT-21 | Exchange rate service unavailable | Passed | *Not yet executed* | May not be safely reproducible in staging |
| IT-22 | OCR product list with a request | Passed | *Not yet executed* | Local OCR was simulated |
| IT-23 | Document import → review → records | Passed | *Not yet executed* | |
| IT-24 | Cancelled import | Passed | *Not yet executed* | |

**Limitations.** The email, exchange rate, and optical character recognition services were simulated locally, so the real services were not exercised. The tests called the server directly and did not operate the Vue.js pages through a browser. Actions by two users at the same moment cannot be reproduced by these tests.

---

## Testing record (for the proponents; not part of the chapter text)

**Environment and isolation**

| Item | Value |
|---|---|
| Database server | MariaDB 10.4.32 (XAMPP), `127.0.0.1:3306` |
| Testing database | `mr_pride_testing` only, through `phpunit.mysql.xml` and the guard in `tests/Capstone/mysql_helpers.php` |
| Test code | `tests/CapstoneMysql/Integration/IntegrationSalesFlowMysqlTest.php` (IT-01 to IT-14), `tests/CapstoneMysql/Integration/IntegrationSupplyFlowMysqlTest.php` (IT-15 to IT-24) |
| Data handling | Each test runs in a transaction that is rolled back; `mr_pride_testing` held no users, sales, or stock movements after the runs |
| `mr_pride` row counts, 48 tables, before and after the three runs | Identical |
| `mr_pride` tables and newest table creation time, before and after | 48 and 2026-11-10 13:53:31 in both |
| Test records in `mr_pride` | None |
| Outbound requests | None; any real external request fails the test |

**Differences from the earlier SQLite run**

1. *IT-19 (finding DB-3 closed).* On SQLite, a supplier delivery dated on the evaluation day was left out of the evaluation, and the test had to evaluate on the following day. On MariaDB the test evaluates on the delivery day itself, without that workaround, and passes. The behaviour was specific to SQLite and does not occur on the database engine the system is deployed on.
2. *All other cases* gave the same results on MariaDB as on SQLite.

**Application version tested.** These runs were made after the Deliveries access correction of 11 November 2026 (six application files; covered by `tests/Feature/DeliveryModuleAccessTest.php`, 15 cases, all passing). After that correction the 25 unit test cases of Section 4.4.1 were run again on MariaDB with the same result as before (23 passed, 2 failed).

**Defects.** No integration case failed. The six defects found by unit testing were corrected afterwards and passed on retest (see [05-unit-testing-mysql-section-4.4.1.md](05-unit-testing-mysql-section-4.4.1.md) and [01-unit-testing-report.md](01-unit-testing-report.md)). After those corrections this integration suite was run again on MariaDB: 24 of 24 passed.
