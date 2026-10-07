# PHASE 05 – RELIABILITY & TEST ISOLATION

## 1. Scope

Implemented the reliability/test-isolation scope from `PHASE_05_SCOPE_PROPOSAL.md`. No product workflow, payment integration, Order schema, deployment or realtime architecture was changed.

## 2. Isolated seed verification

Added `composer seed:verify-isolated`. It requires the configured `COUCHDB_DATABASE` to have a valid `_test` name, creates a unique temporary `_test` database, runs the existing seed importer and `--verify-only` in a child process with only that temporary database selected, then deletes the exact database it created in a `finally` block. It refuses non-`_test` configuration before connecting to CouchDB. Existing `composer seed:verify` behavior is unchanged and still checks only the configured database without writing.

Verification imported and checked all 347 seed documents, then deleted the temporary database. A separate guard check rejected `retail_production`. The configured project database was not selected by the seed importer.

## 3. CouchDB failure-path coverage

- Added `composer couchdb:failure-smoke`, which invokes the real `CouchDbClient` against a closed loopback port and routes the resulting exception through `Router`.
- The test asserts HTTP 503, the generic retry-later response, and absence of the test credential and loopback URL from the response body.
- Existing `composer checkout:smoke` asserts CouchDB stale `_rev` writes return HTTP 409 and the stale delivery history is not saved.
- Existing `composer checkout:http-smoke` asserts missing Order and unauthorized cross-customer/guest requests return 404.
- The CouchDB-unavailable smoke test does not stop or restart the shared CouchDB service; it uses an isolated closed loopback port.

## 4. Database naming and commands

`.env.example` and the Docker Compose fallback both use `retail_order_delivery_test`. The active local `.env` overrides this with `shopquan_ao_sql_migration_test`; that name also has the required `_test` suffix, so it was retained. README now distinguishes direct verification of the configured DB from isolated seed verification and documents the new failure smoke command.

## 5. Tests

- `composer seed:verify-isolated` — PASS; 347 fixture documents imported and verified in a unique temporary database, then the database was deleted.
- Non-`_test` seed verification guard — PASS; refused before connecting to CouchDB.
- `composer couchdb:failure-smoke` — PASS; unavailable CouchDB produces a generic 503 without leaking connection information.
- `composer checkout:smoke` — PASS; includes stale revision 409 behavior.
- `composer checkout:http-smoke` — PASS; includes missing Order and ownership 404 behavior.
- `composer catalog:admin-smoke` — PASS.
- `composer auth:jwt-smoke` — PASS.
- `composer couchdb:diagnostics` — PASS (read-only).
- `php -l scripts/verify_seed_isolated.php` and `php -l scripts/smoke_couchdb_unavailable.php` — PASS.
- `composer validate --no-check-publish` — PASS with the existing advisory that `composer.json` has no `license` field.

## 6. Files modified

- `scripts/verify_seed_isolated.php` (new)
- `scripts/smoke_couchdb_unavailable.php` (new)
- `composer.json`
- `README.md`
- `docs/PHASE_05_SCOPE_PROPOSAL.md`
- `docs/PHASE_05_RELIABILITY_REPORT.md` (new)

## 7. Remaining issues

- `composer seed:verify` still intentionally checks the configured database and may refuse a database containing business documents outside the fixture; use `composer seed:verify-isolated` for a reproducible disposable verification.
- No license was added because the owner has not selected a distribution license.
- Online payment provider and historical product-image snapshot remain future scope decisions.

## 8. Phase 5 status

**DONE.** The proposed reliability/test-isolation acceptance criteria are complete, failure behavior is asserted, temporary data is isolated and removed, and all Phase 1–4 regression suites pass.

No follow-on product phase was started.
