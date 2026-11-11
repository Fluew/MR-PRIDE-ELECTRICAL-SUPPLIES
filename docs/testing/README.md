# MRP ElectroDesk — Testing Documentation (Chapter 4, Sections 4.4.1 and 4.4.2)

Local unit and integration testing, executed on 11 November 2026. Staging and production were not accessed.

| File | Contents |
|---|---|
| [01-unit-testing-report.md](01-unit-testing-report.md) | Report 1: 100 unit test cases (initial run 94 passed, 6 failed; all 100 passed on retest after correction), six defect reports |
| [02-integration-testing-report.md](02-integration-testing-report.md) | Report 2: 24 integration test cases (24 passed locally), database consistency findings, staging table (not yet executed) |
| [03-chapter-4-testing-documentation.md](03-chapter-4-testing-documentation.md) | Report 3: capstone text for 4.4.1 and 4.4.2 |
| [05-unit-testing-mysql-section-4.4.1.md](05-unit-testing-mysql-section-4.4.1.md) | **Revised 4.4.1:** 25 unit test cases on MariaDB (`mr_pride_testing`), Table 4.3 — initial run 23 passed, 2 failed; 25 passed on retest after correction. Run with `php vendor/bin/pest -c phpunit.mysql.xml --testsuite=Unit` |
| [06-integration-testing-mysql-section-4.4.2.md](06-integration-testing-mysql-section-4.4.2.md) | **Revised 4.4.2:** 24 integration test cases on MariaDB (`mr_pride_testing`), Table 4.4 — 24 passed. Staging not yet executed. Run with `php vendor/bin/pest -c phpunit.mysql.xml --testsuite=Integration` |
| [04-staging-verification-checklist.md](04-staging-verification-checklist.md) | Checklist for Railway staging verification (prepared only) |
| [evidence/unit-junit.xml](evidence/unit-junit.xml), [evidence/integration-junit.xml](evidence/integration-junit.xml) | Raw results of the recorded runs |

## Test code (test-only; no application file was changed)

| Path | Contents |
|---|---|
| `tests/Capstone/helpers.php` | Shared fixtures and the database isolation guard |
| `tests/Unit/Capstone/*.php` | UT-01 to UT-100 |
| `tests/Feature/Capstone/*.php` | IT-01 to IT-24 |

## Run again

```
php artisan config:clear
php vendor/bin/pest tests/Unit/Capstone
php vendor/bin/pest tests/Feature/Capstone
```

The tests use the in-memory SQLite database set in `phpunit.xml` and stop before the first query if any other database is configured. **Never run them with staging or production database settings.**

The six defects found by unit testing have been corrected; all capstone tests now pass. Tests that render the 403 or 404 page need either the Vite development server running or a fresh `npm run build`.
