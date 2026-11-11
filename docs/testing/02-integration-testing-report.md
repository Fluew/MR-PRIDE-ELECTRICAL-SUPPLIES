# Report 2 — Integration Testing (Section 4.4.2)

**System:** MRP ElectroDesk (MR-PRIDE Electrical Supplies)
**Date executed (local):** 11 November 2026
**Local result:** 24 integration test cases executed — **24 passed, 0 failed, 0 blocked.**
**Staging result:** **Not yet executed.** Railway staging was not accessed; verification waits for the proponents' explicit approval (see [04-staging-verification-checklist.md](04-staging-verification-checklist.md)).

Every local status comes from an actual run. The raw result file is
[evidence/integration-junit.xml](evidence/integration-junit.xml). The suite was run twice in a row with the same outcome.

---

## 1. Testing objectives

1. Verify that the modules pass data to each other correctly along each business workflow.
2. Verify that the database stays consistent: relationships, statuses, totals and stock.
3. Verify duplicate prevention, error handling and rollback when a step is refused.
4. Verify the integrations with external services (email, exchange rate, OCR) using safe substitutes.

## 2. Incremental integration approach

Integration followed the order in which data moves through the system. Modules were first tested alone (Report 1). Each integration case then adds the next module to a chain that already works, so a failure points to the newly added link.

| Increment | Modules joined | Cases |
|---|---|---|
| 1 | Authentication + role-based access + module pages | IT-01 to IT-03 |
| 2 | Public quotation request + Quotation Management | IT-04 |
| 3 | + Sales Order creation | IT-05, IT-06 |
| 4 | + Payment Recording (receipt, invoice, order confirmation) | IT-07, IT-08 |
| 5 | + Outbound Delivery + Inventory | IT-09, IT-10 |
| 6 | + Demand Forecasting, + Reports and Analytics | IT-11, IT-12 |
| 7 | Quotation Management + Email service | IT-13, IT-14 |
| 8 | Inventory + reorder advice + Purchase Request | IT-15 |
| 9 | + Purchase Order + Inbound Delivery | IT-16 |
| 10 | + Inventory update, + Supplier Evaluation | IT-17 to IT-19 |
| 11 | Procurement + Exchange Rate service | IT-20, IT-21 |
| 12 | OCR / document import + review + record creation | IT-22 to IT-24 |

Each case drives the real routes, controllers, services and models. Only the three external services are replaced: Resend (the application's own test substitute; no API key), Frankfurter (`Http::fake`) and Tesseract OCR (mocked engine). `Http::preventStrayRequests()` makes any real outbound call fail the test.

**Environment:** as in Report 1, section 2 (in-memory SQLite test database; `mr_pride` not used).

**How to reproduce**

```
php artisan config:clear
php vendor/bin/pest tests/Feature/Capstone
```

## 3. Local test cases and results

| ID | Modules Integrated | Test Scenario | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|
| IT-01 | Authentication → Administrator dashboard → all modules | Administrator signs in on a trusted device, opens the dashboard and each module | Signed in; sent to a dashboard; all nine modules open | Authenticated; redirected to `/…/dashboard`; dashboard and nine modules returned 200 | Passed |
| IT-02 | Authentication → Role-based access | Sales staff signs in; opens Sales, the dashboard and other modules | Sent to Sales; dashboard redirects to Sales; other modules refused | Redirected to `/…/sales`; dashboard → Sales; five other modules returned 403 | Passed |
| IT-03 | Authentication → Session and device control | Sign out, then reopen a page; use an account on another account's device | Workspace closed in both cases | Redirected to login; account on the wrong device signed out | Passed |
| IT-04 | Quotation Request → Quotation Management → Inventory (cost) | Customer submits a request; staff see it, price it, submit it; administrator approves | Request listed as Draft with the customer's details; staff cannot approve; approval recorded; cost taken from Inventory; no stock change | Listed with details; staff approval 403; approved by the administrator; total 1,000.00; line cost 150.00; stock 50 | Passed |
| IT-05 | Quotation Acceptance → Sales Order | Customer acceptance is recorded; Sales creates the order; second attempt | One order with the same customer, lines and totals, linked both ways; no stock moved; second attempt refused | Order 336.00, Pending, Unpaid; `quotation_id` and `converted_to_sale_id` linked; line linked to the quotation line; stock 20; second attempt refused | Passed |
| IT-06 | Quotation rejection → Sales Order | Customer rejection is recorded; Sales tries to create an order | No order | Quotation Rejected; request refused; 0 orders | Passed |
| IT-07 | Sales Order → Payment → Receipt → Invoice → Delivery record → Inventory reservation | Payment with proof is recorded, then verified | Recorded payment does not count until verified; then receipt and invoice issued, order Confirmed, balance 0, delivery record opened, stock reserved not removed | Submitted (paid 0.00) → Verified; receipt 224.00, balance 0.00; invoice issued; order Confirmed and Paid; delivery Pending; 2 reserved; stock 10 | Passed |
| IT-08 | Sales Order → Payment (partial, refused) | Partial payment verified; a second payment above the balance is verified; another module tries to record a payment | Balance kept, order stays Pending; refused verification saves nothing; other module refused | Balance 124.00, Partially Paid, Pending, no invoice; second payment still Submitted, 1 receipt, paid 100.00; Inventory staff 403 | Passed |
| IT-09 | Sales Order → Outbound Delivery → Inventory | Release prepared, marked ready, driver assigned, dispatched, delivered with proof; delivery repeated | Stock reserved until delivery; taken out once on delivery; order completed; repeat changes nothing | Stock 10 → 6 with one movement linked to order, release and product; reserved 0; order Completed; repeat: stock 6, 1 movement | Passed |
| IT-10 | Sales Orders → Inventory reservations | Two confirmed orders compete for 5 units; manual stock-out; dispatch of the short order | No unit promised twice | 4 and 1 reserved; available 0; stock-out refused; dispatch refused ("Not enough stock"); stock 5, no movement | Passed |
| IT-11 | Sales → Outbound Delivery → Demand Forecasting | Forecast page before and after a delivered sale of 7 units | No data before; 7 units of demand this month after | `hasData` false → true; this month 7; 2 source records; stock shown 23 | Passed |
| IT-12 | Sales + Inventory + Quotations → Reports and Analytics | Report before and after a delivered sale and a pending order | Sales, revenue, stock value, quotation and top-product figures follow the transactions; pending order not counted | Sales 0 → 1; revenue 448.00; inventory value 600.00 → 360.00; 2 quotations, 2 converted; top product quantity 4; stock 6; Sales activity listed | Passed |
| IT-13 | Quotation Management → Email service | Send before approval; approve; send | Not sent before approval; then emailed to the customer, status Sent, email recorded; no real message leaves | Refused; then Sent to `buyer@example.com`, email log "Sent" with the quotation number; no outbound HTTP | Passed |
| IT-14 | Quotation Management → Email service (failure) | Mail service returns an error | Quotation stays approved and unsent; failure recorded | Status Draft, approved, `sent_at` empty; email log "Failed: Mail server unreachable" | Passed |
| IT-15 | Inventory → Reorder advice → Purchase Request → Procurement | Low-stock product; Inventory staff raise a request from the advice; Procurement views it; non-administrators try to approve | Advice alone creates nothing; request carries stock, forecast and advised quantity; visible in Procurement; only an administrator approves | Advice 145; request Pending with stock 5, forecast 150, advised 145; listed in Procurement; both staff approvals 403 | Passed |
| IT-16 | Purchase Request → Purchase Order → Inbound Delivery | Approval; receive on a draft order; confirm and send; receive 60; receive 41 | Order created once from the request; no receipt on a draft; partial delivery recorded; excess refused | Draft order linked to the request; draft receipt refused; Sent; delivery of 60 Pending Inspection, order Partially Received; 41 refused | Passed |
| IT-17 | Inbound Delivery → Inspection → Inventory | Receive 60; inspect 54 accepted, 6 rejected; post again; inspect again | Only accepted units added, once | Stock 5 → 59; one movement linked to the delivery; second posting refused; stock stays 59; reorder advice reads 59 | Passed |
| IT-18 | Inbound Delivery (two products) → Inventory | One valid and one invalid line; valid delivery; same form sent twice | Nothing saved when one line is invalid; both products updated together; repeat ignored | First attempt: no delivery, no movement, stock unchanged; second: stock 20 and 9, 2 movements; third refused | Passed |
| IT-19 | Inbound Delivery → Inspection → Supplier Evaluation | Evaluation before and after a delivery of 100 (94 accepted) made before the promised date | No scores before; then delivery and quality scores from the records; no final score without a price comparison | Before: none. After: delivery 97.60, quality 94.00, record references the delivery number; price none; evaluation incomplete | Passed |
| IT-20 | Procurement → Exchange Rate service | User chooses PHP; rate requested twice; Procurement page opened | Rate fetched once and cached; page receives it; stored amounts stay in USD | Rate 58.25 returned; one request sent; page shows PHP preference and rate; order still 9.50 / 950.00 | Passed |
| IT-21 | Procurement → Exchange Rate service (failure) | Rate service returns HTTP 503 | Clear "unavailable" answer; Procurement still works; no made-up rate | 503 with message; page loads with no rate; nothing cached; amounts unchanged | Passed |
| IT-22 | OCR → Quotation request → Quotation Management | Customer attaches a picture of a product list (OCR mocked) | Rows read by OCR are kept for staff review with the file; nothing priced or sent | File stored; 3 rows (20 pcs, 100 meters, 50 pieces) marked as read by OCR; 3 unpriced lines; total 0.00; Draft | Passed |
| IT-23 | Document import → Review → Inventory records | Upload a price list; confirm with a row needing review; correct the row; confirm; confirm again | Nothing created before review is complete; then new products only; existing product untouched; no second import | Rows: ready / existing / needs review; first confirm 422; after correction 2 products created with stock 0; existing product unchanged; second confirm 422 | Passed |
| IT-24 | Document import → Cancel | Upload, cancel, then confirm; another module opens the batch | No record created; batch closed to other modules | 0 new products; confirm 422; Procurement staff 403 | Passed |

### Coverage of the required workflows

| # | Workflow | Cases |
|---|---|---|
| 1 | Authentication → Role-Based Dashboard | IT-01, IT-02, IT-03 |
| 2 | Quotation Request → Quotation Management | IT-04 |
| 3 | Quotation Acceptance → Sales Order Creation | IT-05, IT-06 |
| 4 | Sales Order → Payment Recording | IT-07, IT-08 |
| 5 | Inventory → Purchase Request | IT-15 |
| 6 | Purchase Order → Inbound Delivery | IT-16 |
| 7 | Inbound Delivery → Inventory Update | IT-17, IT-18 |
| 8 | Inbound Delivery → Supplier Evaluation | IT-19 |
| 9 | Sales Order → Outbound Delivery → Inventory Update | IT-09, IT-10 |
| 10 | Sales Records → Demand Forecasting | IT-11 |
| 11 | Transactions → Reporting and Analytics | IT-12 |
| 12 | Quotation Management → Email Integration | IT-13, IT-14 |
| 13 | Procurement → Exchange Rate Integration | IT-20, IT-21 |
| 14 | OCR Import → Review → Record Creation | IT-22, IT-23, IT-24 |

## 4. Staging verification results

Staging was **not** accessed. The table is ready for the staging run; the staging column must be filled only from results observed in staging.

| ID | Integration Scenario | Local Result | Staging Result | Remarks |
|---|---|---|---|---|
| IT-01 | Administrator sign-in and dashboard | Passed | Not yet executed | Needs a trusted staging device |
| IT-02 | Staff sign-in to the assigned module | Passed | Not yet executed | Needs a staging staff account |
| IT-03 | Sign-out and device control | Passed | Not yet executed | |
| IT-04 | Quotation request → Quotation Management | Passed | Not yet executed | |
| IT-05 | Accepted quotation → sales order | Passed | Not yet executed | |
| IT-06 | Rejected quotation → no sales order | Passed | Not yet executed | |
| IT-07 | Payment → receipt, invoice, confirmation | Passed | Not yet executed | Check the receipt PDF is kept on the staging volume |
| IT-08 | Partial and refused payment | Passed | Not yet executed | |
| IT-09 | Outbound delivery → inventory | Passed | Not yet executed | |
| IT-10 | Reserved stock is not double-used | Passed | Not yet executed | MySQL row locking applies here; worth checking with two browsers |
| IT-11 | Delivered sale → Demand Forecasting | Passed | Not yet executed | |
| IT-12 | Transactions → Reports | Passed | Not yet executed | Figures will include existing staging data |
| IT-13 | Quotation email sent | Passed | Not yet executed | Local run used a substitute; staging sends through Resend — use a test recipient |
| IT-14 | Quotation email failure | Passed | Not yet executed | Cannot be forced safely in staging; mark Not Applicable unless a failure can be simulated |
| IT-15 | Reorder advice → purchase request | Passed | Not yet executed | |
| IT-16 | Purchase order → inbound delivery | Passed | Not yet executed | |
| IT-17 | Inspection → inventory, once | Passed | Not yet executed | |
| IT-18 | All-or-nothing inbound delivery | Passed | Not yet executed | |
| IT-19 | Delivery → supplier evaluation | Passed | Not yet executed | Evaluate on the delivery day itself, to check finding DB-3 on MySQL |
| IT-20 | Exchange rate shown in Procurement | Passed | Not yet executed | Local run used a faked rate; staging calls Frankfurter |
| IT-21 | Exchange rate service down | Passed | Not yet executed | Cannot be forced safely in staging; mark Not Applicable |
| IT-22 | OCR product list with a request | Passed | Not yet executed | Local OCR was mocked; staging has Tesseract — use a real photo |
| IT-23 | Document import → review → records | Passed | Not yet executed | Needs the worker service or `QUEUE_CONNECTION=sync` |
| IT-24 | Cancelled import | Passed | Not yet executed | |

## 5. Database consistency findings

| # | Area | Finding | Evidence |
|---|---|---|---|
| DB-1 | Relationships | Records created along a workflow are linked in both directions: quotation ↔ sales order, sales line → quotation line, payment → receipt → order, invoice → order, stock movement → order + release + product, purchase request → purchase order → delivery → stock movement | IT-05, IT-07, IT-09, IT-16, IT-17 |
| DB-2 | Inventory accuracy | Stock changed only at the defined points (inspection of an inbound delivery; receipt by the customer), by the exact accepted or received quantity, with a matching movement row (`previous_stock`, `new_stock`). Quotations, orders, payments, purchase requests, purchase orders and uninspected receipts moved no stock | IT-04, IT-05, IT-07, IT-09, IT-15, IT-17 |
| DB-3 | Engine difference (SQLite) | On the SQLite test database, a supplier delivery dated on the same day the evaluation is calculated is left out of the evaluation period, because SQLite compares the stored date-time as text with the period's end date. IT-19 therefore calculates the evaluation the following day. MariaDB/MySQL `DATE` columns are not expected to behave this way, but **this was not verified** and is listed for the staging run | IT-19 (first run failed for this reason; test adjusted, application unchanged) |
| DB-4 | Transaction consistency and rollback | A refused step left no partial data: an over-payment verification left the payment Submitted with no receipt (IT-08); an inbound delivery with one invalid line saved no header, line or movement (IT-18); a stock adjustment that raised an error left the stock unchanged (UT-99) | IT-08, IT-18, UT-99 |
| DB-5 | Duplicate prevention | Enforced for: second sales order per quotation, repeated payment form, repeated bank reference, repeated delivery confirmation (submission key), second stock posting of a delivery, repeated approval of a request, second open request per product, re-confirming an import batch | IT-05, IT-17, IT-18, IT-23, UT-52, UT-65, UT-71 |
| DB-6 | Duplicate prevention — gap | The purchase order CSV import does **not** prevent the same file being imported twice (defect DEF-02 in Report 1) | UT-53 |
| DB-7 | Status updates | Statuses followed the workflow and changed only through their own action: quotation Draft → Sent → Accepted/Rejected; order Pending → Confirmed → Completed; payment Submitted → Verified; purchase request Pending → Converted; purchase order Draft → Sent → Partially Received; delivery Pending → In Transit → Delivered | IT-04 to IT-09, IT-16 |
| DB-8 | Data isolation between teams and roles | Other teams' records did not appear in forecasts or reports; staff were refused outside their module | UT-88, UT-96, IT-02, IT-08, IT-15, IT-24 |

## 6. Defect summary

**No integration test case failed locally.** The defects found during this testing phase came from unit testing and are documented in full in [Report 1, section 5](01-unit-testing-report.md#5-failed-cases-and-defects).

| Defect | Module | Summary | Severity | Found by | Status |
|---|---|---|---|---|---|
| DEF-01 | Procurement | CSV import accepts negative and decimal quantities (`-5` → 5, `2.5` → 25) | High | UT-51 | Open |
| DEF-02 | Procurement | The same purchase order CSV file can be imported twice | Medium | UT-53 | Open |
| DEF-03 | Reporting | Unreadable date in the report date range → HTTP 500 | Low–Medium | UT-92 | Open |
| DEF-04 | Demand Forecasting | Unreadable date in the forecast date range → HTTP 500 | Low–Medium | UT-100 | Open |
| DEF-05 | Inventory | Stock adjustment without the optional remarks field → HTTP 500 | Low | UT-99 | Open |
| DEF-06 | Reporting | "Pending quotations" figures are always zero | Low | UT-97 | Open (expected result to be confirmed by the proponents) |

No defect was corrected. Retesting has not been done.

## 7. Limitations

1. Local integration ran on in-memory SQLite, not on the MariaDB server of XAMPP. Engine-specific behaviour (DB-3, row locking in IT-10) remains to be confirmed on MySQL in staging.
2. Email, exchange rate and OCR were replaced by substitutes, so the real services were not exercised locally.
3. The tests call the server directly. The Vue pages (buttons, forms, client-side validation) were not driven through a browser.
4. Concurrency (two users acting at the same moment) cannot be reproduced by these single-process tests.
