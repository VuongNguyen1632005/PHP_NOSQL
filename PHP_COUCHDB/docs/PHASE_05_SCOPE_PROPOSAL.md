# PHASE 05 – RELIABILITY SCOPE

Status: implemented and verified. See [PHASE_05_RELIABILITY_REPORT.md](PHASE_05_RELIABILITY_REPORT.md).

## Current baseline

Phase 1–4 reports show checkout, order/delivery workflows, UI and realtime are implemented. Required automated tests pass. No Phase 5 acceptance criteria or provider choice exists in the project docs.

## Recommended focus: reliability and test isolation

Close the stabilization items already recorded in `PHASE_01_STABILIZATION_REPORT.md` before adding another customer-facing workflow:

1. Make seed verification reproducible on an isolated disposable `_test` database without modifying the configured database's existing business documents.
2. Add focused regression coverage for CouchDB unavailable, 404 and stale-revision 409 handling where those cases are not already covered.
3. Confirm documented database naming and test commands agree with `.env.example`, Docker Compose and the scripts.
4. Run the complete Phase 1–4 regression suite and record exact outcomes.

### Acceptance criteria

- Seed verification can run against an isolated test database and cleans up only resources created by that run.
- Failure-path tests prove expected application behavior for CouchDB unavailable/404/409; no failure is reported as PASS without an assertion.
- Existing project data is untouched by verification and smoke tests.
- All five required smoke suites and PHP syntax checks pass.
- No new customer features, schema changes, deployment work or realtime expansion are included.

## Other candidate scopes

### Online payment integration

The README says checkout currently creates COD orders; staff can manually verify bank transfer/card after external reconciliation, but there is no gateway integration. This is a meaningful product gap, but implementation needs a user-selected provider, sandbox credentials, callback/webhook requirements and explicit refund scope. No provider or credentials are assumed here.

### Historical product image in order snapshots

The Phase 3 report records that order snapshots do not preserve product images. A later change could add a snapshot image reference and a safe fallback for legacy orders. This would change the order document shape and needs a retention/storage decision.

## Not included in this draft

Payment provider implementation, order image schema changes, production deployment, realtime scale redesign, or Phase 5 code changes.

## Scope decision

The reliability/test-isolation scope above was selected because it is documented, bounded and does not depend on a third-party provider. Payment integration and snapshot images remain separate candidates for a future phase. If online payment is selected, choose the provider and sandbox environment before implementation.
