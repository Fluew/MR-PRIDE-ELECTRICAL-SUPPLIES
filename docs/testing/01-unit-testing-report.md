# Report 1 — Unit Testing (Section 4.4.1)

**System:** MRP ElectroDesk (MR-PRIDE Electrical Supplies)
**Scope:** Local development machine only. No staging or production system was accessed.
**Date executed:** 11 November 2026
**Result:** 100 unit test cases executed — **94 passed, 6 failed, 0 blocked.**

Every status in this report comes from an actual run. The raw result file is
[evidence/unit-junit.xml](evidence/unit-junit.xml). The suite was run twice in a row with the same outcome.

---

## 1. Testing objectives

1. Verify that individual functions, validation rules and calculations of each module give the correct result for valid input.
2. Verify that invalid input is refused and nothing is saved.
3. Verify behaviour at boundary values (smallest and largest accepted values, and the first value outside them).
4. Record every failure with its actual cause, without changing the application.

## 2. Local environment

| Item | Value |
|---|---|
| Operating system | Windows 11 Home Single Language (10.0.26200) |
| Project location | `C:\xampp1\htdocs\MR-Electrical-Supplies` (XAMPP `htdocs`) |
| XAMPP services running | Apache (`httpd`), MariaDB 10.4.32 |
| PHP | 8.3.36 CLI, from `C:\php-8.3.36` (not the PHP bundled with XAMPP) |
| Framework | Laravel 13.38.0, Inertia 3, Vue 3, Tailwind CSS |
| Composer | 2.10.3 |
| Test framework | Pest 4.7.8 (runs on PHPUnit) |
| Test database | SQLite 3.53.4, **in memory** (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` in `phpunit.xml`) |
| Development database | MariaDB `mr_pride` — **not used by the tests** |
| Git branch | `dev` (same commit as `staging` and `main`: `204cec2`) |

### Database isolation (verified before any database-writing test ran)

| Check | Result |
|---|---|
| `phpunit.xml` forces SQLite in memory, `APP_ENV=testing`, array cache/session, sync queue | Confirmed |
| No cached configuration (`bootstrap/cache/config.php`) that could override it | Confirmed (file absent) |
| Guard in `tests/Capstone/helpers.php` stops the run before the first query if the connection is not `sqlite` / `:memory:` | In place; checked again inside every test |
| Row counts of all 48 `mr_pride` tables before and after the runs | 46 identical. `audit_logs` (+3) and `sessions` (+2) changed because the development server was in use in the browser during the run: the three new audit rows are an administrator deleting and re-creating a user account at 08:46 UTC |
| Test fixtures left in `mr_pride` (products `CAP-…`, test teams) | None (0 rows) |
| Resend API key and mail redirect during tests | Blank (`phpunit.xml`); mail driver `array` |
| Outbound HTTP during tests | None. Frankfurter is faked with `Http::fake`; the integration files use `Http::preventStrayRequests()` |

## 3. Testing technique and tools

- **Technique:** black-box functional testing of each unit with equivalence partitioning (valid and invalid classes) and boundary value analysis. Calculations are checked against values worked out by hand, shown in the test data.
- **Levels covered:** (a) pure functions and classes called directly — enums, `ValueParser`, `SkuGenerator`, `SmaForecaster`, `SupplierEvaluator`, `ProductPrioritizer`, `PaymentService`, `FulfillmentService`; (b) the validation rules of one module's own endpoint, called through the Laravel HTTP kernel.
- **Tools:** Pest 4 / PHPUnit, Laravel testing helpers, `RefreshDatabase` on in-memory SQLite, `Storage::fake`, `Http::fake`, a mocked OCR engine (`fakeImageOcr`), fixed clock (`Carbon::setTestNow`) for date-dependent calculations.
- **Mocked external services:** Resend (email), Frankfurter (exchange rate), Tesseract (OCR).
- **Test files (new, test-only):** `tests/Unit/Capstone/*.php` and `tests/Capstone/helpers.php`. No application file was changed.

**How to reproduce**

```
php artisan config:clear
php vendor/bin/pest tests/Unit/Capstone
```

## 4. Unit test cases and actual results

### 4.1 Authentication and Access Administration

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| UT-01 | Login | Valid credentials on an authorized device | Registered email, correct password, trusted-device cookies | User is authenticated and redirected to a dashboard | Authenticated; redirected to `/…/dashboard` | Passed |
| UT-02 | Login | Wrong password | Registered email, `wrong-password` | Login refused with an error on `email`; no session | Error on `email`; user stays a guest | Passed |
| UT-03 | Login | Required fields | Empty email and password | Errors on `email` and `password` | Errors on both fields; guest | Passed |
| UT-04 | Login | Unknown account | `nobody@example.com` | Login refused | Error on `email`; guest | Passed |
| UT-05 | Device authorization | Login from an unregistered device | Valid credentials, no device cookies | Request blocked; no session | HTTP 404; guest | Passed |
| UT-06 | Password policy | Password strength rule | 11 chars; 12 chars; no upper; no lower; no number; no symbol; empty | Only the 12-character mixed password passes | 12-character password accepted; the six others refused | Passed |
| UT-07 | Change password | Current-password check, no reuse, confirmation | Wrong current; new = current; confirmation mismatch; valid change | First three refused; valid change saved and user sent to login | As expected; hash updated, `must_change_password` cleared, history kept | Passed |
| UT-08 | `User::isPasswordExpired` | 90-day expiry | 89, 90, 91 days; never changed; forced change; customer account | Expired from day 90; customers never | 89 → not expired; 90, 91, null, forced → expired; customer → not expired | Passed |
| UT-09 | `EnsureRole::allows` | Role-based permissions | Admin, sales, inventory, legacy `staff`, reports, customer against module lists | Each role opens only its own module; admin opens all | As expected for all seven cases | Passed |
| UT-10 | `User::syncModules` | Explicit module assignments | Role `staff_sales` given `inventory`, `reports`, `not-a-module` | Only the two valid modules; old role grants nothing | Modules `[inventory, reports]`; Sales refused | Passed |
| UT-11 | `TrustedDevice::findActiveByCredential` | Device recognition | Valid cookies; forged credential; missing credential; unknown device; none; revoked device | Only the valid, approved device is recognised | Valid → device; all others → none | Passed |
| UT-12 | `TrustedDevice::isAuthorizedForUser` | One device, one account | Owner; another user; device set to pending | Owner only, while approved | Owner true; other false; pending false | Passed |
| UT-13 | `User::initialPasswordFor` | Temporary password format | `juan carlos`, `MARÍA`, empty, `1234`, `J` | `Juan1234`, `Maria1234`, `User1234`, `User1234`, pattern-valid | As expected | Passed |
| UT-14 | Account status | Active / inactive accounts | `active`, `pending_activation`, `inactive`, `suspended`; inactive staff opens Inventory | Only the first two usable; inactive account blocked | As expected; inactive staff signed out with HTTP 404 | Passed |

### 4.2 Quotation Management

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| UT-15 | Quotation request form | Required fields | Empty form | Errors on company, contact name, email, phone; nothing saved | Four errors; 0 quotations | Passed |
| UT-16 | Quotation request form | Field formats | Email `not-an-email`; project type `Spaceport` | Both refused | Errors on `email` and `projectType` | Passed |
| UT-17 | Quotation request form | Valid request | Complete contact and project details | Saved as Draft | 1 Draft quotation, total 0.00 | Passed |
| UT-18 | Quotation line | Quantity validation | 0, −1, 1.5, `abc`, empty; then 1 | Invalid values refused; 1 accepted | Five refusals; quantity 1 saved | Passed |
| UT-19 | Quotation totals | Subtotal, discount, tax, total | 4 × 250.00, discount 100.00, tax 12% | 1,000.00; 100.00; 108.00; 1,008.00 | 1,000.00; 100.00; 108.00; 1,008.00 | Passed |
| UT-20 | Staff quotation form | Required and range checks | No lines; no client name; price −1; tax 101% | All refused | Four refusals; 0 quotations | Passed |
| UT-21 | Brand validation | Existing brand | `schneider electric` (lower case) | Accepted; stored as `Schneider Electric` | Stored with catalog spelling | Passed |
| UT-22 | Brand validation | Brand not in catalog | `Made Up Brand` | Refused | Error on `brands`; 0 quotations | Passed |
| UT-23 | Brand validation | Unlisted brand | Option ticked with blank text; then `Omron, Legrand` | Blank refused; names saved | Refused, then saved with a `BRAND-UNLISTED` line | Passed |
| UT-24 | `QuotationStatus` | Status transitions | Draft→Sent, Draft→Accepted, Sent→Accepted, Sent→Draft, Accepted→any, Rejected→Draft, Expired→Sent, value `pending` | Only defined transitions allowed | As expected for all cases; `pending` is not a status | Passed |
| UT-25 | Quotation status action | Manual status validation | `accepted`, `sent`, `not-a-status`, empty | All refused; quotation stays Draft | Four refusals; still Draft | Passed |
| UT-26 | File upload | Type, size and count limits | `.exe`; script renamed `.pdf`; 25 MB + 1 KB; six files | All refused; nothing stored | Four refusals; 0 quotations; no stored file | Passed |
| UT-27 | File upload | Supported file | PNG image | Accepted and stored | File stored with the request | Passed |
| UT-28 | OCR field extraction | Rows read from an image (OCR mocked) | Three OCR lines: breaker 20 pcs, wire 100 meters, conduit 50 pieces | Three rows with description and quantity | 3 rows; quantities 20, 100, 50 | Passed |
| UT-29 | OCR field extraction | OCR unavailable | OCR engine reports not installed | No rows; clear message | 0 rows; message says the file cannot be read automatically | Passed |
| UT-30 | `ValueParser` | Extracted field validation | `₱1,250.00`, `1.250,50`, `(15.00)`, `75 /pc`, `abc`, OCR `l5` and `1O`, `10 pcs`, `25 Rolls`, unit `bucket`, serial date `45000` | Numbers parsed; OCR look-alikes corrected and reported; unreadable values refused | 1250, 1250.5, −15, 75; `abc` refused; `l5`→15 and `1O`→10 with correction noted; units parsed; unknown unit refused | Passed |

### 4.3 Inventory Management

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| UT-31 | Product form | Required fields | Empty form | Errors on name, category, price, stock | Four errors; 0 products | Passed |
| UT-32 | Product form | Invalid numbers | Price −1; cost −0.01; stock −1; stock 1.5; price `abc`; reorder level −5 | All refused | Six refusals; 0 products | Passed |
| UT-33 | Product form | Valid product | Breaker, stock 20, reorder level 10 | Saved with generated SKU and opening movement | SKU `BRK-000001`; movement 0 → 20 | Passed |
| UT-34 | Stock status | Reorder-level boundaries | Stock 0, 1, 10, 11 with reorder level 10 | Out of Stock, Low, Low, In Stock | As expected | Passed |
| UT-35 | `SkuGenerator` | SKU prefix and sequence | Breakers, wiring, Conduits, Panel Boards, empty, null; existing `BRK-000120` | BRK, WIR, CND, PNL, GEN, GEN; sequence continues after 120 | As expected; next SKU `BRK-000121` | Passed |
| UT-36 | Stock-in | Quantity validation and calculation | 0, −5, 2.5, `ten`, empty, unknown product; then 5 on stock 10 | Invalid refused; 10 + 5 = 15 | Six refusals; stock 15; movement 10 → 15 | Passed |
| UT-37 | Stock-out | Quantity above available stock | 11, 0, −3 on stock 10; no reason; then 10 | Refused; exact stock accepted; never negative | Refusals with "Available quantity: 10"; stock 0, Out of Stock | Passed |
| UT-38 | `Product::availableStock` | Available = on hand − reserved | Stock 10, 4 reserved; then stock 3 | 6; then 0 (not negative) | 6; then 0 | Passed |
| UT-39 | Stock adjustment | Count validation and difference | Count −1; 7.5; invalid reason; count 7; count 0 | Invalid refused; 10 → 7 records a difference of 3; 0 accepted | As expected | Passed |
| UT-99 | Stock adjustment | Optional remarks field omitted | Count 7, valid reason, no `remarks` field | Adjustment saved (the field is optional) | **HTTP 500**; stock stays 10 | **Failed** |
| UT-40 | Low-stock calculation | Threshold classification | (0,5) (5,5) (6,5) (10,none) (11,none) | Out, Low, In Stock, Low, In Stock | As expected (default threshold 10) | Passed |
| UT-41 | `Product::stockStatusFor` | Status for a requested quantity | Stock 20, level 5: request 1, 14, 15, 20, 21; none available | Available, Available, Low, Low, Insufficient, Not Available | As expected | Passed |
| UT-42 | Reorder recommendation | Reorder level and forecast shortfall | Stock 5 vs forecast 150; stock 500; at level without history; above level without history | Suggest 145; none; suggest reorder quantity 30; none | 145; 0; 30; 0 | Passed |
| UT-43 | Product priority scale | Score → 1–5 scale and class | 0, 100, 87.5, 150, −10; 80, 80.25, 20, 20.25, 50 | 1.0, 5.0, 4.5, clamped; High, Very High, Very Low, Low, Moderate | As expected | Passed |

### 4.4 Procurement

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| UT-44 | Purchase request | Required fields | No lines; empty lines; no justification | All refused | Three refusals; 0 requests | Passed |
| UT-45 | Purchase request | Quantity validation | 0, −3, 2.5, empty, `ten`, 1,000,001; then 1 and 1,000,000 | Invalid refused; both boundaries accepted; stock unchanged | Six refusals; 1 and 1,000,000 saved; stock 5 | Passed |
| UT-46 | Purchase request | Product not listed | No description; no unit; unit `bucket`; valid | First three refused; valid saved as unlisted | As expected | Passed |
| UT-47 | Purchase order | Field validation | No supplier; unknown supplier; quantity 0, −5; cost 0, −1, `free`; promised date yesterday; then cost 0.01, date today | Invalid refused; boundary values saved | Eight refusals; 0.01 saved | Passed |
| UT-48 | Purchase order cost | Quantity × unit cost | 100 × 450.00; 3 × 29.50; 1 × 2,200.00; file total 2,000 for 5 × 350 | 45,000.00; 88.50; 2,200.00; mismatch reported | As expected; mismatch reported as 1,750.00 | Passed |
| UT-49 | Imported purchase order | Field mapping | Headers `UOM, Price, Supplier, Desc, SKU, Qty, PO No., Order Date` in a different order; file without required columns | Each column mapped to its field; incomplete file refused | All eight fields mapped and saved; HTTP 422 for the incomplete file | Passed |
| UT-50 | Imported purchase order | Invalid rows | Quantity 0; no description; impossible date; no date; negative cost | All five reported; none saved | 5 invalid rows with reasons; 0 saved | Passed |
| UT-51 | Imported purchase order | Negative and decimal quantity | Quantity `-5` and `2.5` | Both rows refused | **Both accepted: −5 read as 5, 2.5 read as 25** | **Failed** |
| UT-52 | Duplicate records | Duplicate request and repeated approval | Same product requested twice; request approved twice | Second request refused; one purchase order | Refused ("already has an open purchase request"); 1 purchase order | Passed |
| UT-53 | Duplicate records | Same purchase order file imported twice | One-row file, imported two times | Second import does not repeat the record | **2 identical purchase order rows** | **Failed** |

### 4.5 Supplier Evaluation

Fixed weights: Delivery 40% (on-time 60%, fulfillment 40%), Price 35%, Quality 25%.

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| UT-54 | Delivery timeliness | On-time and fulfillment rates | 2 deliveries, 1 on time; 97 of 100 accepted | (50 × 0.60) + (97 × 0.40) = 68.80; weighted 27.52 | 68.80; 27.52 | Passed |
| UT-55 | Delivery timeliness | Promised-date boundary | Delivered on the promised date; one day later | 100% on time; 0% on time | 100.00; 0.00 (score 40.00) | Passed |
| UT-56 | Price competitiveness | Lowest cost ÷ own cost | Costs 1,100 and 1,000 | 90.91 and 100.00; weighted 31.82 | 90.91; 100.00; 31.82 | Passed |
| UT-57 | Price competitiveness | No comparable supplier | One supplier only | No price score; no final score or rank | Score null; incomplete; unranked | Passed |
| UT-58 | Product quality | Accepted ÷ inspected | 97 accepted, 3 rejected; all 20 rejected | 97.00 (weighted 24.25); 0.00 | 97.00; 24.25; 0.00 | Passed |
| UT-59 | Evaluation | Supplier without records | No deliveries | No score, class or rank; three criteria missing | As expected | Passed |
| UT-60 | Final score | Weighted sum, class, rank | Alpha 68.80 / 90.91 / 97.00; Beta 100 / 100 / 100 | Alpha 83.59 "Very Good", rank 2; Beta 100.00 "Excellent", rank 1 | As expected; formula `27.52 + 31.82 + 24.25 = 83.59` | Passed |
| UT-61 | `SupplierEvaluator::classify` | Class boundaries | 100, 90, 89.99, 80, 79.99, 70, 69.99, 60, 59.99, 0 | Excellent, Excellent, Very Good, Very Good, Good, Good, Fair, Fair, Needs Improvement ×2 | As expected | Passed |
| UT-62 | Evaluation data | Records left out | Repeated delivery number; cancelled delivery; delivery 16 months old | Counted once; others ignored | 1 inspection, 50 accepted, 0 rejected | Passed |

### 4.6 Sales and Payment Recording

Orders in these cases: 2 × 100.00 + 12% tax = 224.00.

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| UT-63 | Sales order | Field validation | No quotation; unknown id; `abc`; fulfillment `courier` | All refused | Four refusals; 0 orders | Passed |
| UT-64 | Sales order | Source quotation status | Draft, Sent, Rejected, Expired; then Accepted | Only Accepted creates an order with the same totals | Four refusals; order 224.00, Pending, Unpaid; stock unchanged | Passed |
| UT-65 | Duplicate prevention | Second order from one quotation | Same accepted quotation twice | Second refused | "already converted"; 1 order | Passed |
| UT-66 | Payment | Amount, method, date | 0, −50, `abc`, empty, 0.001, 100,000,000; method `Cash`; tomorrow; `not-a-date`; then 0.01 | Invalid refused; 0.01 accepted | Ten refusals; 0.01 saved | Passed |
| UT-67 | Payment | Proof file | `.exe`; PDF of 5 MB + 1 KB; PNG image | First two refused; image stored | As expected | Passed |
| UT-68 | Amount paid | Only verified payments count | Submitted 100; rejected; verified 100; refunded | 0; 0; 100; 0 | 0.00; 0.00; 100.00; 0.00 (balance back to 224.00) | Passed |
| UT-69 | Remaining balance | After each verified payment | 100.00, then 124.00 | 124.00 Partially Paid; 0.00 Paid | 124.00; 0.00; two receipts | Passed |
| UT-70 | Payment amount | Above the balance | 224.01; 0; then 224.00 | First two refused; exact balance accepted | Refused; refused; verified, balance 0.00 | Passed |
| UT-71 | Duplicate prevention | Repeated form, repeated reference, repeated verification | Same idempotency key twice; `bdo 555` vs `BDO-555`; verify a verified payment | One payment; second reference refused; re-verification refused | 1 payment; refused; refused; 1 receipt | Passed |
| UT-72 | Payment | Cancelled order | Payment for a cancelled order | Refused | Refused; 0 payments | Passed |

### 4.7 Delivery Management

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| UT-73 | Inbound delivery | Quantity validation | 7 + 2 ≠ 10; 21 of 20; accepted −1; rejected without reason; nothing received | All refused; stock unchanged | Five refusals; stock 25; no record | Passed |
| UT-74 | Inbound delivery | Stock adjustment | Received 20: 17 accepted, 3 rejected; stock 25 | 25 + 17 = 42 | Stock 42; movement 25 → 42 | Passed |
| UT-75 | Quick receipt | Quantity and date | 0, −4, 2.5, 21 of 20; tomorrow; then 20 | Invalid refused; 20 accepted; no stock before inspection | Five refusals; 20 recorded; stock 25 | Passed |
| UT-76 | Inbound correction | Difference only; not below zero | 10 → 8 accepted; then 8 → 2 with only 3 in stock | Stock −2; second refused | 35 → 33; refused, stock 3 | Passed |
| UT-77 | Outbound release | Quantity validation | 6 of 5; 0; unknown line; −1; then 5 | Invalid refused; 5 accepted; no stock moved | Four refusals; release of 5; stock 25 | Passed |
| UT-78 | Outbound receipt | Received quantity | 6 of 5 released; 0 | Both refused | Refused; stock 25 | Passed |
| UT-79 | Outbound delivery | Stock adjustment | 5 released, 3 received; stock 25 | 25 − 3 = 22; 2 stay reserved | Stock 22; movement 25 → 22; 2 remaining | Passed |

### 4.8 Demand Forecasting

Simple Moving Average; clock fixed at 15 October 2026.

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| UT-80 | `SmaForecaster::series` | Forecast accuracy | Jul 10, Aug 20, Sep 30 | Oct = (10 + 20 + 30) / 3 = 20 | 20 | Passed |
| UT-81 | `SmaForecaster::series` | Rounding | (10,10,11) (1,2,2) (1,1,2) (0,0,1) (1,2,0) | 10, 2, 1, 0, 1 | 10, 2, 1, 0, 1 | Passed |
| UT-82 | `SmaForecaster::series` | Zero-demand periods | Jul 30, nothing after | Oct = 10; Nov = 0 | 10; 0 | Passed |
| UT-83 | `SmaForecaster` | Missing historical data | Product with no records | No forecast, with reason; not zero | `hasData` false; forecast null; reason given | Passed |
| UT-84 | `SmaForecaster::window` | Period validation | 3, 6, 12, `"6"`, 5, 0, −3, 13, null, `abc` | 3, 6, 12, 6, then default 3 | As expected | Passed |
| UT-85 | `SmaForecaster::series` | Boundary: range vs window | 3 months; 4 months; 1 month; 4 months with window 6 | None; one forecast (12); none; none | As expected | Passed |
| UT-86 | Monthly demand | Source of demand | Movements 15, confirmed order 25, pending 40, cancelled 40, stock-in 99 | 25 (larger of 15 and 25) | 25 | Passed |
| UT-87 | `SmaForecaster::forProducts` | Period forecast | 40, 50, 60 (3 months); 6-month history 210; product 6 weeks old | 50/month and 150; 35/month and 210; no forecast for the new product | As expected | Passed |
| UT-88 | Data filtering | Other products and teams | 7 (this product), 100 (other product), 1,000 (other team) | 7; team total 107 | 7; 107; other team 1,000 | Passed |

### 4.9 Reporting and Analytics

| ID | Function / Module | Test Description | Test Data / Input | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| UT-89 | Report date range | Custom range, inclusive | Sales on Sep 30, Oct 1, Oct 10, Oct 11; range Oct 1–10 | 2 sales, 600.00 | 2 sales; 600.00 | Passed |
| UT-90 | Report date range | Presets | Today, last 7 days, this month, this year, all time, unknown preset | 100; 300; 700; 700; 1,500; falls back to all time | As expected | Passed |
| UT-91 | Report date range | Reversed range | From Oct 10 to Oct 1 | No error; no sales | 0 sales; 0.00 | Passed |
| UT-92 | Report date range | Unreadable date | `from_date=not-a-date` | Handled without a server error | **HTTP 500** | **Failed** |
| UT-93 | Report aggregation | Revenue | Confirmed 100, processing 200, completed 400, pending 800, cancelled 1,600 | 3 sales; 700.00 | 3; 700.00 | Passed |
| UT-94 | Report aggregation | Inventory value and stock levels | 10 × 60, 2 × 25, 0 × 10, inactive product | 650.00; 1 in stock, 1 low, 1 out | 650.00; 1 / 1 / 1 | Passed |
| UT-95 | Report aggregation | Summary equals category table | Product with cost 0.00 | Both figures equal | Equal | Passed |
| UT-96 | Data filtering | Conversion rate; other teams | 4 quotations, 1 converted; another team's records | 25.0%; other team excluded | 25.0%; revenue 112.00 | Passed |
| UT-97 | Report aggregation | Pending quotations figure | One quotation waiting for approval (224.00) | Count 1; value 224.00 | **Count 0; value 0.00** | **Failed** |
| UT-98 | Forecast filters | Date range and period limits | From after To; To in the future; From before 2020; period 7; period 12 | Jan 1; today; 2020-01-01; 3; 12 | As expected | Passed |
| UT-100 | Forecast filters | Unreadable date | `from_date=not-a-date` | Handled without a server error | **HTTP 500** | **Failed** |

## 5. Failed cases and defects

No defect was fixed. The recommended corrections are suggestions for the proponents to review.

### DEF-01 — Negative and decimal quantities are accepted by the purchase order CSV import

| Field | Detail |
|---|---|
| Test case ID | UT-51 |
| Affected module | Procurement — purchase order CSV import |
| Test input | CSV rows with QUANTITY `-5` and `2.5`, unit cost 10 |
| Expected result | Both rows are reported as invalid (quantity must be a whole number of at least 1) |
| Actual result | Both rows are valid: `-5` is imported as **5** (total 50.00) and `2.5` as **25** (total 250.00) |
| Error message | None — the rows are accepted silently |
| Probable cause | `app/Http/Controllers/PurchaseRequestController.php` line 410 removes every character that is not a digit (`preg_replace('/[^0-9]/', '', …)`) before checking the quantity, so the minus sign and the decimal point are discarded |
| Severity | High — wrong quantities and costs are stored without any warning |
| Recommended correction | Validate the raw value as a whole number ≥ 1 (allowing only thousands separators) and report anything else as an invalid row |
| Retest status | Not retested — no correction applied |

### DEF-02 — The same purchase order file can be imported twice

| Field | Detail |
|---|---|
| Test case ID | UT-53 |
| Affected module | Procurement — purchase order CSV import |
| Test input | The same one-row file (PO `4000005472`) imported twice with `execute = true` |
| Expected result | The second import does not create the line again (it is reported as already recorded) |
| Actual result | Two identical purchase order rows exist |
| Error message | None |
| Probable cause | `importPurchaseOrdersCsv` creates a row for every valid line without checking for an existing line with the same PO number, item and quantity |
| Severity | Medium — imported orders are closed historical records and move no stock, but duplicates inflate purchase order counts and totals |
| Recommended correction | Before saving, report rows that match an existing line of the same team (PO number + item/SKU + description) and skip them, or ask for confirmation |
| Retest status | Not retested — no correction applied |

### DEF-03 — An unreadable date in the Reports date range causes a server error

| Field | Detail |
|---|---|
| Test case ID | UT-92 |
| Affected module | Reporting and Analytics |
| Test input | `GET /{team}/reports?date_preset=custom&from_date=not-a-date&to_date=2026-10-10` |
| Expected result | The request is handled without a server error (the date is refused or ignored) |
| Actual result | HTTP 500 |
| Error message | `Carbon\Exceptions\InvalidFormatException: Could not parse 'not-a-date': Failed to parse time string (not-a-date) at position 0 (n)` |
| Probable cause | `ReportController::index` passes `from_date` / `to_date` to `Carbon::parse()` without validating them |
| Severity | Low to Medium — the page's date pickers send valid dates, so it needs a hand-typed address; the page then fails completely |
| Recommended correction | Validate both inputs with the `date` rule (and `to_date` after or equal to `from_date`) before parsing |
| Retest status | Not retested — no correction applied |

### DEF-04 — An unreadable date in the Demand Forecasting date range causes a server error

| Field | Detail |
|---|---|
| Test case ID | UT-100 |
| Affected module | Demand Forecasting (page and CSV export use the same code) |
| Test input | `GET /{team}/forecast?from_date=not-a-date` |
| Expected result | The request is handled without a server error |
| Actual result | HTTP 500 |
| Error message | `Carbon\Exceptions\InvalidFormatException: Could not parse 'not-a-date': …` |
| Probable cause | `ForecastController::index` and `exportCsv` call `Carbon::parse()` on unvalidated input |
| Severity | Low to Medium (same reasoning as DEF-03) |
| Recommended correction | Validate `from_date` and `to_date` with the `date` rule before parsing |
| Retest status | Not retested — no correction applied |

### DEF-05 — A stock adjustment sent without the optional remarks field causes a server error

| Field | Detail |
|---|---|
| Test case ID | UT-99 |
| Affected module | Inventory Management — stock adjustment |
| Test input | `POST /{team}/inventory/adjust` with product, `physical_count = 7`, a valid reason, and no `remarks` field |
| Expected result | The adjustment is saved (remarks is validated as optional) |
| Actual result | HTTP 500; the stock stays 10 (the transaction is rolled back, so no partial data is left) |
| Error message | `ErrorException: Undefined array key "remarks"` at `InventoryController.php:556` |
| Probable cause | The code reads `$validated['remarks']`, which does not exist when the field is not sent |
| Severity | Low — the Inventory page always sends the field (empty when nothing is typed), and UT-39 confirms that path works |
| Recommended correction | Read it as `$validated['remarks'] ?? null` |
| Retest status | Not retested — no correction applied |

### DEF-06 — The "pending quotations" figures in Reports are always zero

| Field | Detail |
|---|---|
| Test case ID | UT-97 |
| Affected module | Reporting and Analytics — summary metrics |
| Test input | One Draft quotation submitted for approval, total 224.00 |
| Expected result | Pending quotations: count 1, value 224.00 |
| Actual result | Count 0, value 0.00 |
| Error message | None |
| Probable cause | `ReportController` filters on `status = 'Pending'`, but the quotation statuses are `draft`, `sent`, `accepted`, `rejected`, `expired`; no quotation can match |
| Severity | Low — a summary figure is wrong; no stored data is affected |
| Recommended correction | Decide what "pending" means (for example Draft quotations waiting for approval, or Sent quotations waiting for the customer) and filter on that. **The expected result of UT-97 is the tester's reading of the label and should be confirmed by the proponents.** |
| Retest status | Not retested — no correction applied |

### Retest after correction (11 November 2026)

The six defects were corrected afterwards, at the proponents' request. The defect tables above keep the results as first found. All 100 cases were then run again: **100 passed, 0 failed.**

| Defect | Test | Correction applied | Retest |
|---|---|---|---|
| DEF-01 | UT-51 | Import quantity is read as written and must be a whole number of at least 1 (`ValueParser::wholeQuantity`); the same correction was applied to the quotation CSV import, which had the same code | Passed |
| DEF-02 | UT-53 | A line already recorded for the same purchase order (number, item, description, quantity) is reported and not imported again | Passed |
| DEF-03 | UT-92 | An unreadable report date is treated as not given | Passed |
| DEF-04 | UT-100 | An unreadable forecast date is treated as not given (page and CSV export) | Passed |
| DEF-05 | UT-99 | A missing remarks field is treated as empty | Passed |
| DEF-06 | UT-97 | "Pending quotations" counts Draft quotations submitted for approval and not yet approved. **This meaning was chosen by the tester and should be confirmed by the proponents** | Passed |

## 6. Summary

*The figures below are those of the initial run. After the corrections all 100 cases pass (see the retest above).*

| Module | Executed | Passed | Failed | Blocked |
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

IDs UT-99 and UT-100 were added during testing, when a first run exposed two behaviours that deserved their own case; they are listed with their modules above.

## 7. Limitations and observations

1. **Database engine.** The tests ran on SQLite in memory, as the project's `phpunit.xml` is set up. The development and deployed databases are MariaDB/MySQL. Behaviour that depends on the engine (date comparison, locking, strict mode) is not proven by these runs. One such difference was seen in integration testing (see Report 2, finding DB-3). The suite has not been run against a MariaDB test database.
2. **OCR.** Tesseract is not installed on this machine (`pdftotext` is). OCR was mocked, so the tests check what the system does with OCR output, not the accuracy of the OCR engine.
3. **Front-end validation.** Vue form validation was not unit tested here; the project has eight Vitest files under `tests/js` that were not part of this run. All validation results above are from the server.
4. **Existing test suite (baseline).** The project's own 882 tests were run once before the new tests: 869 passed and 10 failed. All 10 failures had one cause: `public/build/manifest.json` is older than `resources/js/pages/Forbidden.vue` and `NotFound.vue`, so the 403 and 404 pages could not be rendered from the built assets. After the Vite development server was started, the same 27 tests in those two files passed. Rebuilding the assets (`npm run build`) removes the dependency on the development server.
5. **Local configuration.** `.env` has `APP_URL=htpp://localhost:8000` (`htpp` instead of `http`). It did not affect these tests; it can affect generated links.
