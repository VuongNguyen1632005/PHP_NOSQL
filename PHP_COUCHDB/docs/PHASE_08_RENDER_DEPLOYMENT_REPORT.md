# Phase 08 – Render Deployment + Final Demo

**Status: DEPLOYMENT BLOCKED / NOT DEPLOYED.** This report records the pre-deployment audit and its blockers. No Render service was created, no source was pushed, and no production credentials or secrets are recorded here.

## Current audit

- Runtime: PHP 8.3 with Apache from `php:8.3-apache`; Composer installs production dependencies in Docker; app document root is `/var/www/html/public`.
- Local application port: Apache listens on `*:80`; the existing Compose mapping is loopback `127.0.0.1:8081`. Render web services default to port `10000`; the deployment must explicitly route to `80` or change Apache and test it before deployment.
- Health endpoints: `/health` returns process health; `/health/ready` checks the configured CouchDB database and returns 503 when it is unavailable.
- CouchDB: local Compose uses CouchDB `3.5.0`, HTTP `5984`, and data path `/opt/couchdb/data`. PHP reads `COUCHDB_URL`, `COUCHDB_USER`, `COUCHDB_PASSWORD`, and `COUCHDB_DATABASE` from the environment.
- Session/JWT: the session cookie uses `Secure` only when PHP sees `$_SERVER['HTTPS']`; Render terminates TLS at its proxy and forwards HTTP to the service. Forwarded-protocol behavior and secure-cookie handling need an online verification/fix before Security can PASS. JWT source reads `JWT_SECRET`, `JWT_ISSUER`, `JWT_AUDIENCE`, and `JWT_TTL_SECONDS`.
- PHP error configuration: the upload INI only sets upload limits; it does not explicitly configure `display_errors=Off` and `log_errors=On`. Router-handled failures return generic responses, but PHP-level failure behavior and logs were not executable/verified. `APP_ENV` is not read by current runtime code.
- Media: repository product images are in `public/assets`; uploaded images are stored outside the public root at `var/uploads` and served by `/media/{file}`. This path needs its own persistent disk mounted at `/var/www/html/var/uploads`.
- Other runtime setting: `ORDER_SSE_CHANGES_TIMEOUT_MS` defaults to 8000 ms; Phase 4 SSE must be verified on Render after deployment.
- Windows path scan: no Windows absolute filesystem dependency was found in runtime PHP. Matches from the scan were URLs or test-only loopback sockets.
- Image build context: fixed `.dockerignore` so all local `var/` runtime files are excluded. This prevents `Dockerfile`'s `COPY .` from baking `var/phase06_demo_credentials.txt` or SQL snapshot exports into the image. The Dockerfile recreates an empty upload directory. Docker build verification is still blocked below.
- Render account: the Render connector is available and the configured workspace could be read; the workspace has no service for this PHP/CouchDB project. Existing services belong to another project and were not modified.

## Deployment architecture

```text
Internet / HTTPS
        ↓
PHP 8.3 + Apache Render Web Service (public)
        │
        │ Render private network, HTTP :5984
        ↓
CouchDB 3.5.0 Render Private Service
        ├── persistent disk: /opt/couchdb/data
        └── no public CouchDB/Fauxton access

PHP uploads persistent disk: /var/www/html/var/uploads
```

Both persistent disks require paid Render services. Persistent-disk services cannot scale horizontally and Render disables zero-downtime deploys for services with disks. Budget/plan selection is not yet approved. Local Fauxton remains the safe NoSQL presentation environment.

## Blockers

1. **No Git repository or remote:** `D:\PHP_NOSQL\PHP_COUCHDB` is not a Git repository. Render's Git-backed build cannot read this project until it is placed in a GitHub/GitLab/Bitbucket repository and pushed. No remote was created or guessed.
2. **No local build/test execution:** Docker CLI is installed, but Docker Engine returned permission denied for its Windows named pipe. PHP, Composer, and Render CLI are not installed in the current shell. Local GET checks to `127.0.0.1:8082/health`, `/health/ready`, and `127.0.0.1:5984/_up` were unavailable. Therefore required build, smoke, readiness, and local-preservation checks are UNVERIFIED.
3. **Persistent-disk cost choice:** Render requires paid services for attached disks. Do not create paid resources until the owner chooses/approves a plan and its current dashboard cost.
4. **HTTPS cookie behavior:** PHP's current secure-cookie detection only checks `HTTPS`; Render terminates TLS upstream. Confirm and, if needed, make the smallest trusted-proxy adjustment and test it before public auth use.
5. **Production PHP error mode:** explicitly configure and test error display/logging so PHP-level errors cannot render implementation paths or stack traces.
6. **No live service to verify:** there is no public URL, database service, mounted disk, environment configuration, or deployment log for this project.

Per the Phase 08 rule, deployment stopped before creating services. No live URL is available.

## Results

```text
PHASE 08 RESULT

Overall:
BLOCKED

Deployment architecture:
PHP public Web Service → private CouchDB 3.5.0 service; separate persistent disks for CouchDB data and uploaded product images.

PHP Render:
NOT DEPLOYED

CouchDB:
NOT DEPLOYED

Persistent DB storage:
UNVERIFIED

Persistent image storage:
UNVERIFIED

Environment:
NOT CONFIGURED

Health:
UNVERIFIED

Readiness:
UNVERIFIED

Customer flow online:
NOT DEPLOYED

Admin flow online:
NOT DEPLOYED

Order workflow online:
NOT DEPLOYED

Delivery workflow online:
NOT DEPLOYED

Realtime online:
UNVERIFIED

Desktop:
NOT DEPLOYED

Mobile 390px:
NOT DEPLOYED

Security:
PARTIAL — router responses hide normal exceptions and local secrets are excluded from Docker context; forwarded HTTPS secure-cookie behavior and explicit production PHP error settings remain unresolved.

Logs:
UNAVAILABLE

Restart persistence:
UNVERIFIED

Local environment after deploy:
UNVERIFIED — no deployment occurred; current local HTTP endpoints were unavailable from this shell.

Public URL:
NONE

Files modified:
- .dockerignore
- docs/RENDER_ENV_CHECKLIST.md
- docs/RENDER_DEPLOYMENT_GUIDE.md
- docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md

Render configuration created:
NONE — no render.yaml or service was created because source publishing, paid disk plan selection, and local validation are unresolved.

Tests passed:
- Read-only project/configuration audit.
- Render workspace/service inventory read; no project resources created or modified.
- Docker build context now excludes the complete var/ directory (static configuration check only).
- `docker compose config --quiet`: PASS (Compose configuration syntax/schema only; does not build or start containers).

Tests failed:
- Not executed: docker compose/build/test commands could not reach Docker Engine (permission denied).
- Not executed: PHP/Composer commands unavailable in host shell.
- Not executed: local health/readiness/CouchDB checks returned unavailable.

Deployment blockers:
- Project has no Git repository or remote for Render source builds.
- Local Docker Engine is inaccessible, so required validation has not passed.
- Owner must approve paid services for persistent CouchDB and image disks.
- HTTPS secure-cookie behavior behind Render's TLS proxy must be verified/fixed.
- Explicit production PHP error display/log settings must be established and tested.

Remaining issues:
- Complete local build, Composer validation, all main smoke tests, and CouchDB diagnostics.
- Publish reviewed source to a Git remote connected to Render.
- Choose paid plan/disk sizes; set secrets in Render only.
- Deploy private CouchDB and PHP, seed the synthetic dataset, verify disk write permissions, and complete all online/mobile/realtime/security/restart checks.

Rollback:
READY AS A PLAN — roll back the PHP deploy and keep both data disks; take/verify CouchDB backup before any database change. The project backup script intentionally refuses production database names, so a production-safe backup procedure must be selected and tested before online data changes.

Final demo:
NOT READY ONLINE — localhost/Fauxton fallback plan documented, but local endpoints were unavailable during this audit.

Phase 08 final status:
NOT DONE

Ready for final report/presentation:
NO

Reason:
The app is not deployed, and mandatory persistence, security, online-flow, restart, and local validation checks are not verified.

NEXT RECOMMENDED ACTION
Restore Docker Engine access and Git source publishing, then rerun all local validation gates; review paid Render disk costs and approve a plan before creating services.
```

## References

- [Render Web Services and port binding](https://render.com/docs/web-services#port-binding)
- [Render Persistent Disks](https://render.com/docs/disks)
- [Render Private Services](https://render.com/docs/private-services)
- [Render private networking](https://render.com/docs/private-network)
- [Render environment variables and secrets](https://render.com/docs/configure-environment-variables)
- [Render deploy behavior](https://render.com/docs/deploys)
