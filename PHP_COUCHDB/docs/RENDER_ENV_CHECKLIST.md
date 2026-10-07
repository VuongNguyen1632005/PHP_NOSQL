# Render Production Environment Checklist

This checklist is based on the current PHP runtime reads in `public/index.php`, the local Compose file, and the Phase 6 demo seeder. Never paste secret values into this file, source control, browser screenshots, or deployment logs.

## PHP Web Service

| Variable | Required | Production value / notes |
|---|---:|---|
| `APP_ENV` | Recommended | `production`. The current PHP code does not branch on this value; do not treat it as a debug-mode switch. |
| `PORT` | Yes on Render | `80` while using the existing Apache `*:80` listener. Render defaults to `10000`; if the app keeps its current listener, explicitly configure Render to route to port 80. |
| `COUCHDB_URL` | Yes | `http://<CouchDB-private-host>:5984`, using the internal address from the CouchDB service's Render Connect page. Never use a public CouchDB URL. |
| `COUCHDB_DATABASE` | Yes | `shopquan_ao_phase06_demo_test` when serving the Phase 6 demo dataset. Choose and document a different name only if the dataset setup is changed deliberately. |
| `COUCHDB_USER` | Yes | Set to the CouchDB service admin username or a dedicated app account after one is created. Store as a secret. |
| `COUCHDB_PASSWORD` | Yes | Set the same secret configured on the CouchDB service. Store as a secret. |
| `JWT_SECRET` | Yes | Random secret of at least 32 bytes. Store as a secret and never include it in a commit or report. Rotating it invalidates existing tokens. |
| `JWT_ISSUER` | Yes | The deployed HTTPS app origin, e.g. `https://<php-service>.onrender.com`; set to the actual assigned URL. |
| `JWT_AUDIENCE` | Yes | `webthoitrang-api`, matching the current local configuration unless the API contract is intentionally changed. |
| `JWT_TTL_SECONDS` | Optional | `900` (current fallback). |
| `ORDER_SSE_CHANGES_TIMEOUT_MS` | Optional | `8000` (current fallback and maximum in `OrderRealtimeController`). Verify SSE on the deployed proxy before claiming realtime PASS. |

## CouchDB Private Service

| Variable | Required | Production value / notes |
|---|---:|---|
| `COUCHDB_USER` | Yes | Unique admin username; store as a secret. |
| `COUCHDB_PASSWORD` | Yes | Strong unique password; store as a secret. |

Use the same credential pair in the PHP service. Set `COUCHDB_URL` from the private-network hostname and CouchDB's listening port `5984`. Do not publish port `5984` to the Internet.

## One-time demo seed only

| Variable | Required | Production value / notes |
|---|---:|---|
| `PHASE06_DEMO_DATABASE` | Optional | `shopquan_ao_phase06_demo_test`; this is the seeder's default. |
| `PHASE06_DEMO_PASSWORD` | Recommended | Supply a temporary demo password as a secret while running `composer demo:seed`. The seeder writes credentials to `var/phase06_demo_credentials.txt`; do not print or download that file. Remove the temporary secret after the demo accounts are verified and rotate demo passwords if needed. |

The seeder refuses to target a database whose name equals `COUCHDB_DATABASE`. For the one-time seed command only, set `COUCHDB_DATABASE` to a different harmless guard name while keeping `PHASE06_DEMO_DATABASE` set to the demo database; the seeder sets the target database for its index-install child process itself. Never run `composer demo:reset` against the online database.

## Local-only Compose variables

`APP_PORT`, `COUCHDB_BIND_ADDRESS`, `COUCHDB_PORT`, and the local URL defaults are for `docker-compose.yml`; they are not needed by Render. The PHP source currently does not read `APP_URL` or `APP_ENV`, so do not add fictitious app settings as a substitute for the actual variables above.
