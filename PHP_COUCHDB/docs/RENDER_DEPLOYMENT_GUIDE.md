# Render Deployment Guide

This is a deployment runbook for the existing PHP 8.3 + Apache + CouchDB project. It does not claim that a Render service has been created or that any online behavior has passed. Complete the blockers in the Phase 08 report before applying this guide.

## Target architecture

```text
Browser --HTTPS--> PHP 8.3 / Apache Web Service
                         |
                         | Render private network, HTTP :5984
                         v
                   CouchDB 3.5.0 Private Service
                   disk: /opt/couchdb/data

PHP uploaded product images
disk: /var/www/html/var/uploads
```

Only the PHP web service receives public requests. CouchDB and Fauxton remain private; local Fauxton remains available for the NoSQL presentation. Use one Render region for both services (Singapore is a reasonable choice for the existing local environment), and use the exact private hostname shown by Render rather than guessing a hostname.

## Prerequisites

1. Put the project in a Git repository hosted by GitHub, GitLab, or Bitbucket and push the reviewed deployment commit. The current project directory is not a Git repository and has no remote, so Render cannot build this source yet.
2. Connect that Git provider to the Render workspace and choose the intended workspace/region.
3. Approve the paid service plans required for persistent disks. Render disks are available on paid web/private services; both uploaded-image and CouchDB persistence are required for Phase 08 DONE. Review current costs in the dashboard before creating services.
4. Install/use an available PHP runtime or Docker engine and pass the local build/test gates listed below. Do not apply deployment while any gate is unverified.
5. Review the Docker build context. `.dockerignore` now excludes all of `var/` because this directory can contain demo credentials and SQL snapshot exports. The Dockerfile creates an empty upload directory in the image; mount persistent image storage only at runtime.
6. Add and test explicit production PHP settings (`display_errors=Off`, `log_errors=On`) and verify that PHP-level errors do not render paths or stack traces. `APP_ENV` is not read by the current PHP code, so setting it alone does not establish production-safe PHP behavior.

## Build and service configuration

### PHP web service

- Create a Docker Web Service from the repository root with the existing `Dockerfile` and Docker context `.`.
- The image is based on `php:8.3-apache`, serves `/var/www/html/public`, and currently listens on Apache port 80. Set Render's service `PORT` to `80` unless the Apache listener is deliberately changed and tested. Render's default is `10000`; a mismatch can cause a failed deploy/502.
- Set the health check to `/health`. After CouchDB and the demo database are initialized, verify `/health/ready` also returns 200.
- Set production environment values from [RENDER_ENV_CHECKLIST.md](RENDER_ENV_CHECKLIST.md). Never copy `.env` to Render, and do not put credentials into Docker build args.
- Add a paid persistent disk mounted at `/var/www/html/var/uploads`. This is the exact PHP upload directory. Confirm `www-data` can create, read, and delete a test image after the disk is mounted; the image's build-time ownership alone does not verify mounted-disk permissions.

### CouchDB private service

- Create a Docker Private Service from the official `couchdb:3.5.0` image.
- Set `COUCHDB_USER` and `COUCHDB_PASSWORD` as Render secrets. Configure its HTTP health check at `/_up` if supported by the selected private-service setup.
- Attach a paid persistent disk at `/opt/couchdb/data`. Confirm the CouchDB process can write there and that the disk is mounted before initialization.
- Do not add a public port, public URL, or public Fauxton access. Give the PHP service `COUCHDB_URL` using the private host and port `5984` shown in Render's Connect page.
- Keep one CouchDB instance: a single attached disk is not a clustered/high-availability CouchDB setup.

Render's current documentation states that service filesystems are ephemeral without a disk, disks require paid services, a disk prevents scaling that service, and attaching disks disables zero-downtime deploys for that service. Plan the demo around a single instance and a short maintenance window for deploys. See [Persistent Disks](https://render.com/docs/disks), [Private Services](https://render.com/docs/private-services), and [Deploys](https://render.com/docs/deploys).

## Database initialization and demo data

Use a new, empty demo database; never point the seed/reset command at an existing customer or production database.

1. Wait for CouchDB `/_up` and confirm the PHP service can reach the CouchDB private hostname.
2. In the PHP service shell, set the one-time variables described in [RENDER_ENV_CHECKLIST.md](RENDER_ENV_CHECKLIST.md), including a temporary `PHASE06_DEMO_PASSWORD` secret and a guard `COUCHDB_DATABASE` that differs from the demo database.
3. Run `composer demo:seed` once with `PHASE06_DEMO_DATABASE=shopquan_ao_phase06_demo_test`. This script creates the database, installs the domain validator, loads the synthetic Phase 6 dataset, installs indexes, and advances sample delivery states. It refuses to overwrite an existing database.
4. Run `composer demo:verify`; then set the PHP service's regular `COUCHDB_DATABASE` to the seeded demo database and redeploy/restart as appropriate.
5. Check `/health/ready`, the catalog, and a demo login before opening the app publicly. Do not use `composer demo:reset` online.
6. Remove the one-time password environment variable after provisioning is complete. Treat the seeder's credential file as secret and do not expose it through the web root, logs, screenshots, or support bundles.

## Local validation gates

Run from the project/container using the exact scripts declared in `composer.json`:

```powershell
docker compose up --build -d
docker compose exec -T app composer validate
docker compose exec -T app sh -lc 'find src public templates scripts -type f -name "*.php" -print0 | xargs -0 -n1 php -l'
docker compose exec -T app composer checkout:smoke
docker compose exec -T app composer checkout:http-smoke
docker compose exec -T app composer catalog:admin-smoke
docker compose exec -T app composer couchdb:diagnostics
docker compose exec -T app composer demo:verify
```

The lint command runs inside the Linux container with project-relative paths; do not pass Windows host paths to PHP. `demo:verify` requires the Phase 6 demo database to exist. Record each actual result in the Phase 08 report. A skipped command is UNVERIFIED, not PASS.

## Post-deploy verification

1. Check Render build/start events and recent logs for PHP warnings/fatals, CouchDB connection failures, 5xx, SSE errors, and media errors. Do not include environment-variable values in logs or screenshots.
2. Verify `https://<actual-service-url>/health` and `/health/ready` return 200; verify CouchDB `/_up` and PHP-to-CouchDB access from the private network.
3. Run the customer flow: home/catalog/product, register/login, cart, checkout, order history/detail, delivery timeline, review.
4. Run the staff/manager flow: admin orders, status changes, delivery updates, failed delivery/retry, delivered, revenue, and review moderation. Confirm success/error messages.
5. Create one synthetic online order and move it through pending → confirmed → packing → shipping, then delivery created → picked_up → in_transit → out_for_delivery → delivered. Confirm the customer sees the final state.
6. If Phase 4 realtime is enabled, open customer/admin in separate browsers and prove that the customer receives an update without refresh. Render/proxy behavior remains UNVERIFIED until this test is performed against the real deployment.
7. Test mobile at 390px for catalog, cart, checkout, customer order detail/timeline, and admin order detail. Test 404, unauthenticated, forbidden, CSRF, invalid order transitions, and verify no response exposes paths, stack traces, or secrets.
8. Upload a small synthetic image through admin, reload it, restart/redeploy safely, and confirm both the file and CouchDB document remain. Do not mark storage PASS until the post-restart checks succeed.
9. Verify local `127.0.0.1:8081` and Fauxton still work after any deployment-specific source changes.

## Fauxton and demo fallback

- Local Fauxton: `http://127.0.0.1:5984/_utils/` (AVAILABLE when the local Compose stack is running).
- Production CouchDB/Fauxton: keep private and do not expose it for a public demo. Use local Fauxton for `_id`, `_rev`, validator, Mango Query, and index demonstrations.
- Keep a localhost PHP/CouchDB fallback for the presentation so the NoSQL demo does not depend entirely on Internet availability.

## Rollback

1. Roll the PHP service back to its last known-good Render deploy from the service's Deploys page. Persistent disks disable zero-downtime deploys, so expect a brief restart window.
2. Do not delete or reset the CouchDB service/disk when rolling back the app.
3. Before any database/design-document change, take and verify a separate CouchDB backup or replication copy. The current project backup script is deliberately restricted to `_test` databases; do not bypass that guard to back up or restore an online database.
4. If uploaded images fail after rollback, keep the image disk attached and mount it back at `/var/www/html/var/uploads`; never replace it with a new empty disk without an explicit recovery decision.

## References

- [Render Docker Web Services and port binding](https://render.com/docs/web-services#port-binding)
- [Render Persistent Disks](https://render.com/docs/disks)
- [Render Private Services](https://render.com/docs/private-services)
- [Render private networking](https://render.com/docs/private-network)
- [Render environment variables and secrets](https://render.com/docs/configure-environment-variables)
- [Render deploy behavior](https://render.com/docs/deploys)
