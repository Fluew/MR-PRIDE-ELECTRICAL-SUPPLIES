# Report 3 — Chapter 4 Academic Documentation

> **Notes for the proponents (delete before submission)**
>
> - The National Teachers College sample was not available while this draft was prepared. The headings and tables follow the formats given in the testing instructions. Compare the layout with the college sample and adjust headings, numbering and table captions where they differ.
> - Every figure below comes from the test runs of 11 November 2026 on the local machine. Nothing was estimated.
> - Staging verification has not been performed. The paragraph and table for staging are marked and must be completed only with results observed in staging.
> - Table numbers (4.x) are placeholders; renumber them to fit the chapter.
> - The detailed test cases are long. If the chapter has a page limit, keep the summary tables here and move the full tables (Reports 1 and 2) to an appendix.

---

## 4.4 Testing

### 4.4.1 Unit Testing

Unit testing was conducted to verify that the individual functions, validation rules, and calculations of MRP ElectroDesk produce correct results before the modules were combined. The proponents tested each unit in isolation in the local development environment, using the project copy installed under XAMPP.

**Testing environment.** The tests were executed on a Windows 11 computer with PHP 8.3.36, Laravel 13.38.0, and Composer 2.10.3. The proponents used Pest 4.7.8, which runs on PHPUnit, as the testing framework. To protect the existing records, the tests did not use the development database (`mr_pride` on MariaDB 10.4.32). Each test built its own temporary SQLite database in memory, which was discarded when the test finished. The proponents confirmed this isolation before the tests were run and compared the row counts of the development database before and after testing; no test record was written to it.

**Testing technique.** The proponents applied black-box functional testing. Test data were selected through equivalence partitioning, which groups inputs into valid and invalid classes, and boundary value analysis, which tests the smallest and largest accepted values and the first values outside them. Calculations such as quotation totals, remaining balances, supplier scores, and demand forecasts were checked against values computed by hand. External services were replaced by controlled substitutes: the email service (Resend), the exchange rate service (Frankfurter), and the optical character recognition engine (Tesseract).

**Scope.** One hundred (100) unit test cases were prepared and executed across nine modules. Table 4.x summarizes the results.

**Table 4.x. Summary of Unit Testing Results**

| Module | Test Cases Executed | Passed | Failed | Blocked |
|---|---:|---:|---:|---:|
| Authentication and Access Administration | 14 | 14 | 0 | 0 |
| Quotation Management | 16 | 16 | 0 | 0 |
| Inventory Management | 14 | 13 | 1 | 0 |
| Procurement | 10 | 8 | 2 | 0 |
| Supplier Evaluation | 9 | 9 | 0 | 0 |
| Sales and Payment Recording | 10 | 10 | 0 | 0 |
| Delivery Management | 7 | 7 | 0 | 0 |
| Demand Forecasting | 9 | 9 | 0 | 0 |
| Reporting and Analytics | 11 | 8 | 3 | 0 |
| **Total** | **100** | **94** | **6** | **0** |

Of the 100 test cases, 94 passed and 6 failed. No test case was blocked.

Table 4.x presents representative unit test cases. The complete list of test cases, with the test data and actual result of each, is provided in Appendix __.

**Table 4.x. Representative Unit Test Cases**

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| UT-01 | Login | Valid credentials on an authorized device | Registered email and correct password | User is authenticated and redirected to a dashboard | User was authenticated and redirected to a dashboard | Passed |
| UT-05 | Device authorization | Login from an unregistered device | Valid credentials, no registered device | Request is blocked | Request was blocked (HTTP 404); no session was created | Passed |
| UT-06 | Password policy | Password strength | Passwords of 11 and 12 characters; passwords without an uppercase letter, a lowercase letter, a number, or a symbol | Only the 12-character password with all character types is accepted | Only the 12-character password was accepted | Passed |
| UT-18 | Quotation line | Quantity validation | 0, −1, 1.5, "abc", blank; then 1 | Invalid quantities are refused; 1 is accepted | The five invalid values were refused; 1 was accepted | Passed |
| UT-19 | Quotation totals | Total computation | 4 × 250.00, discount 100.00, tax 12% | Grand total of 1,008.00 | Grand total was 1,008.00 | Passed |
| UT-26 | File upload | File type and size restrictions | Executable file; script renamed as PDF; file of 25 MB + 1 KB; six files | All are refused | All were refused; no file was stored | Passed |
| UT-37 | Stock-out | Quantity above available stock | 11 units when 10 are available; then 10 | 11 is refused; 10 is accepted and stock becomes 0 | 11 was refused; stock became 0 after 10 | Passed |
| UT-42 | Reorder recommendation | Suggested reorder quantity | Stock 5, reorder level 10, forecast demand 150 | Reorder is recommended with a quantity of 145 | A quantity of 145 was recommended | Passed |
| UT-51 | Imported purchase order | Negative and decimal quantity | Quantities −5 and 2.5 | Both rows are refused | Both rows were accepted as 5 and 25 | Failed |
| UT-60 | Supplier evaluation | Final weighted score | Delivery 68.80, price 90.91, quality 97.00 | Score of 83.59, classified "Very Good" | Score was 83.59, classified "Very Good" | Passed |
| UT-69 | Payment recording | Remaining balance | Order of 224.00; payments of 100.00 and 124.00 | Balance of 124.00, then 0.00 | Balance was 124.00, then 0.00 | Passed |
| UT-71 | Payment recording | Duplicate transaction prevention | Same payment form sent twice; same bank reference used for two payments | One payment is recorded; the second reference is refused | One payment was recorded; the second reference was refused | Passed |
| UT-74 | Inbound delivery | Stock adjustment | Stock 25; 20 received, 17 accepted, 3 rejected | Stock becomes 42 | Stock became 42 | Passed |
| UT-80 | Demand forecasting | Forecast accuracy | Monthly demand of 10, 20, and 30 | Forecast of 20 | Forecast was 20 | Passed |
| UT-83 | Demand forecasting | Missing historical data | Product with no sales records | No forecast is produced, with a reason | No forecast was produced; the reason was shown | Passed |
| UT-89 | Report date range | Date-range filtering | Four sales; range of October 1 to 10 | Two sales totaling 600.00 | Two sales totaling 600.00 | Passed |
| UT-92 | Report date range | Unreadable date | Date value "not-a-date" | Request is handled without a server error | A server error (HTTP 500) was returned | Failed |

**Defects found.** The six failed test cases revealed six defects, summarized in Table 4.x. The proponents documented each defect and did not modify the system during this testing phase; corrections and retesting are scheduled as a separate activity.

**Table 4.x. Defects Identified During Unit Testing**

| Defect | Test Case | Module | Description | Severity |
|---|---|---|---|---|
| DEF-01 | UT-51 | Procurement | The purchase order file import accepts negative and decimal quantities; −5 is stored as 5 and 2.5 as 25 | High |
| DEF-02 | UT-53 | Procurement | The same purchase order file can be imported twice, creating duplicate records | Medium |
| DEF-03 | UT-92 | Reporting and Analytics | An unreadable date in the report date range causes a server error | Low to Medium |
| DEF-04 | UT-100 | Demand Forecasting | An unreadable date in the forecast date range causes a server error | Low to Medium |
| DEF-05 | UT-99 | Inventory Management | A stock adjustment sent without the optional remarks field causes a server error; no data is changed | Low |
| DEF-06 | UT-97 | Reporting and Analytics | The "pending quotations" figures are always zero | Low |

The remaining 94 test cases confirmed that the validation rules refuse invalid, negative, and out-of-range inputs, that computed values match the manually computed values, and that the system does not produce a forecast or a supplier score when the required records are missing.

**Limitations.** The unit tests were executed on a SQLite database rather than on the MariaDB server; the optical character recognition engine was simulated, because Tesseract was not installed on the local computer; and the validation built into the Vue.js forms was not included, as the tests exercised the server directly.

### 4.4.2 Integration Testing

Integration testing was conducted to verify that the modules of MRP ElectroDesk exchange data correctly and keep the database consistent when they work together. Testing was performed first in the local development environment. Verification in the Railway staging environment follows the local testing.

**Approach.** The proponents used an incremental integration approach. The modules were first tested individually during unit testing. The modules were then combined one at a time, following the order in which data moves through the business process, so that a failure could be traced to the most recently added module. For example, the quotation request was first integrated with Quotation Management, then with Sales Order creation, then with Payment Recording, and then with Outbound Delivery and Inventory. Each test case used the actual routes, controllers, services, and models of the system. Only the external services were replaced by substitutes, and the tests were set to fail if any real external request was attempted.

**Scope.** Twenty-four (24) integration test cases were executed, covering the fourteen workflows listed in Table 4.x.

**Table 4.x. Summary of Local Integration Testing Results**

| Workflow | Test Cases | Passed | Failed |
|---|---|---:|---:|
| Authentication → Role-Based Dashboard | IT-01 to IT-03 | 3 | 0 |
| Quotation Request → Quotation Management | IT-04 | 1 | 0 |
| Quotation Acceptance → Sales Order Creation | IT-05, IT-06 | 2 | 0 |
| Sales Order → Payment Recording | IT-07, IT-08 | 2 | 0 |
| Sales Order → Outbound Delivery → Inventory Update | IT-09, IT-10 | 2 | 0 |
| Sales Records → Demand Forecasting | IT-11 | 1 | 0 |
| Transactions → Reporting and Analytics | IT-12 | 1 | 0 |
| Quotation Management → Email Integration | IT-13, IT-14 | 2 | 0 |
| Inventory → Purchase Request | IT-15 | 1 | 0 |
| Purchase Order → Inbound Delivery | IT-16 | 1 | 0 |
| Inbound Delivery → Inventory Update | IT-17, IT-18 | 2 | 0 |
| Inbound Delivery → Supplier Evaluation | IT-19 | 1 | 0 |
| Procurement → Exchange Rate Integration | IT-20, IT-21 | 2 | 0 |
| OCR Import → Review → Record Creation | IT-22 to IT-24 | 3 | 0 |
| **Total** | **24** | **24** | **0** |

All 24 integration test cases passed in the local environment. Table 4.x presents representative test cases; the complete list is provided in Appendix __.

**Table 4.x. Representative Integration Test Cases (Local)**

| ID | Modules Integrated | Test Scenario | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|
| IT-02 | Authentication → Role-based access | A sales staff member signs in and opens other modules | The user is sent to Sales; other modules are refused | The user was sent to Sales; the other modules returned "forbidden" | Passed |
| IT-05 | Quotation → Sales Order | An accepted quotation is converted into a sales order twice | One sales order with the same customer, items, and totals; the second attempt is refused | One sales order of 336.00 was created and linked to the quotation; the second attempt was refused | Passed |
| IT-07 | Sales Order → Payment → Receipt and Invoice | A payment is recorded and then verified | The payment counts only after verification; a receipt and an invoice are issued; the order is confirmed | The balance became 0.00; a receipt and an invoice were issued; the order became Confirmed | Passed |
| IT-09 | Sales Order → Outbound Delivery → Inventory | A confirmed order is released, dispatched, and delivered | Stock is deducted once, on delivery; the order is completed | Stock decreased from 10 to 6 with one stock movement; the order became Completed | Passed |
| IT-17 | Inbound Delivery → Inventory | A delivery of 60 units is inspected: 54 accepted, 6 rejected | Only the 54 accepted units are added, once | Stock increased from 5 to 59; a second posting was refused | Passed |
| IT-18 | Inbound Delivery → Inventory (rollback) | A delivery with one valid and one invalid line is submitted | Nothing is saved | No delivery, stock movement, or stock change was recorded | Passed |
| IT-19 | Inbound Delivery → Supplier Evaluation | A delivery is received before the promised date; 94 of 100 units are accepted | Delivery and quality scores are computed from the records | Delivery score was 97.60 and quality score was 94.00 | Passed |
| IT-21 | Procurement → Exchange Rate service | The exchange rate service is unavailable | Procurement continues to work; no converted amount is shown | The page loaded without a rate; stored amounts were unchanged | Passed |
| IT-23 | Document import → Review → Inventory | A price list is imported, a flagged row is corrected, and the import is confirmed twice | Records are created only after review, and only once | Two products were created after the correction; the second confirmation was refused | Passed |

**Database consistency.** The integration tests showed that related records were linked correctly across modules, such as the quotation and its sales order, the payment and its receipt, and the stock movement and its delivery. Stock quantities changed only when an inbound delivery was inspected or when a customer received the goods, and by the exact accepted or received quantity. When a step was refused, no partial data remained: a refused payment verification left no receipt, and an inbound delivery with one invalid line saved nothing. The system prevented duplicate sales orders, payments, delivery confirmations, stock postings, and import confirmations. One gap was identified during unit testing: the purchase order file import does not prevent the same file from being imported twice (DEF-02).

**Staging verification.** *[To be completed after staging verification.]* At the time of writing, the integration scenarios have been executed only in the local environment. Verification in the Railway staging environment has not yet been performed. Before it begins, the proponents will confirm the staging branch and deployment configuration, that the staging database is separate from the production database, that the staging credentials are not the production credentials, and that the email and other external services use safe test settings. The staging results will be recorded separately from the local results in Table 4.x.

**Table 4.x. Local and Staging Integration Results**

| ID | Integration Scenario | Local Result | Staging Result | Remarks |
|---|---|---|---|---|
| IT-01 | Administrator sign-in and dashboard | Passed | *Not yet executed* | |
| IT-02 | Staff sign-in to the assigned module | Passed | *Not yet executed* | |
| … | *(remaining scenarios as listed in Report 2, section 4)* | | | |

**Limitations.** The local integration tests ran on a SQLite database, so behaviour specific to MySQL remains to be confirmed in staging. One such difference was observed: on SQLite, a supplier delivery dated on the day of evaluation was not included in the evaluation period, and the corresponding test (IT-19) computes the evaluation on the following day. The email, exchange rate, and optical character recognition services were simulated locally. The tests exercised the server directly and did not operate the Vue.js pages through a browser.
