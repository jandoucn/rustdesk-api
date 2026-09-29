# Implementation and test summary

Updated: 2026-09-29

## Delivered architecture

- One PHP route tree and one Web UI for SQLite and MySQL.
- PDO storage abstraction with parameter binding, transactions, upsert and insert-ignore helpers.
- SQLite legacy migration with verified pre-migration backup.
- MySQL 8.4 schema: 18 InnoDB/utf8mb4_unicode_ci tables, no hard-coded credentials or default administrator.
- Container internal port `80`; Compose external mapping `7000:80`.
- Management prefix comes only from `RUSTDESK_ADMIN_PATH`, default `/ops-console`.
- `/`, `/admin`, and unknown non-API paths render `home.html`; only the configured prefix exposes management pages and APIs.

## API inventory

Client endpoints:

| Method | Path |
| --- | --- |
| POST | `/api/login`, `/api/currentUser`, `/api/logout` |
| GET | `/api/login-options`, `/api/users`, `/api/peers` |
| GET/POST | `/api/ab` |
| POST | `/api/ab/get`, `/api/ab/settings`, `/api/ab/personal`, `/api/ab/shared/profiles`, `/api/ab/peers`, `/api/ab/tags/{guid}` |
| POST/PUT/DELETE | `/api/ab/peer/add/{guid}`, `/api/ab/peer/update/{guid}`, `/api/ab/peer/{guid}` |
| POST/PUT/DELETE | `/api/ab/tag/add/{guid}`, `/api/ab/tag/rename/{guid}`, `/api/ab/tag/update/{guid}`, `/api/ab/tag/{guid}` |
| GET | `/api/device-group/accessible` |
| POST | `/api/devices/deploy`, `/api/devices/cli`, `/api/sysinfo`, `/api/sysinfo_ver`, `/api/heartbeat` |
| POST/PUT | `/api/audit/conn`, `/api/audit/file`, `/api/audit/alarm`, `/api/audit` |
| POST | `/api/switch-grant`, `/api/record` |

Management endpoints use `${RUSTDESK_ADMIN_PATH}/api`:

| Method | Relative path | Behavior |
| --- | --- | --- |
| GET | `/session` | Session state and CSRF token |
| POST | `/login`, `/logout` | Administrator authentication |
| PATCH | `/me/password` | Change own password and revoke sessions |
| GET/POST | `/users` | Search/list or create |
| PATCH/DELETE | `/users/{id}` | Edit, disable, password change, soft delete |
| GET | `/devices` | Search/list API clients and online state |
| PATCH | `/devices/{id}/alias` | Update the current administrator's personal address-book alias |
| DELETE | `/devices/{id}` | Remove deployment and report records |
| GET | `/address-book` | List the current administrator's merged personal address book |
| POST | `/address-book/peers` | Add a personal address-book peer |
| PATCH/DELETE | `/address-book/peers/{id}` | Update or remove a peer in all compatible stores |
| POST | `/address-book/tags` | Add a personal address-book tag |
| PATCH/DELETE | `/address-book/tags/{name}` | Exact tag update/rename or removal |
| PATCH | `/address-book/favorites/{id}` | Set administrator-scoped Web favorite state |

`GET /devices` accepts `q`, `presence=online|recent|offline|unreported`, `labelled=1`, `page`, and `pageSize`. Its `summary` contains `total`, `online`, `recent`, `offline`, `unreported`, and `labelled` counts. Inventory starts from heartbeat/sysinfo reports and merges deployment metadata; a deployment is not required.

Presence is calculated from `last_heartbeat`: 20 seconds or less is `online`, 21 through 90 seconds is `recent`, more than 90 seconds is `offline`, and a deployment without a heartbeat is `unreported`. The console polls every 5 seconds while visible and pauses polling while the document is hidden.

The alias endpoint is session and CSRF protected. It updates the signed-in administrator's personal `ab_profile_peers` row, `rustdesk_peers` compatibility row, and exact `address_books.payload` in one transaction while preserving unknown JSON fields. RustDesk receives the new alias on its next address-book pull.

The address-book page merges legacy-only and current-profile-only peers for the signed-in administrator, with current-profile fields taking precedence for duplicate IDs. Peer and exact-tag CRUD remains transactional across the current profile, compatibility row and exact legacy payload. Contact removal deliberately leaves device inventory rows intact. Web favorites are stored per administrator in `admin_peer_favorites`; they are management filters and are not RustDesk client-native favorites.

## Real data verification

SQLite suite (`tests/integration_test.py`): **25/25 passed in 4.430 seconds** on the latest run. It creates a real legacy four-table database, migrates it to schema v5, starts a real HTTP server, performs CRUD, and checks stored rows. Coverage includes legacy preservation, token lifecycle, exact address-book JSON, sysinfo/heartbeat, modal-user API CRUD with one-character passwords, session revocation, soft delete, nonce deduplication, new address-book CRUD, deploy/CLI, audit notes, switch grants, binary record chunks, client removal, custom-path isolation, heartbeat-only inventory, exact 20/21/90/91-second presence function boundaries, summary/filter behavior, alias authorization/CSRF/input validation, transactional three-store alias consistency, empty alias behavior, unknown-field preservation, and RustDesk API readback. The added personal-address-book tests verify current-user isolation, legacy/profile merging with profile precedence, three-store peer CRUD, old/current RustDesk API readback, inventory preservation on contact removal, exact tag rename/delete, administrator-scoped idempotent favorites, favorite cleanup, validation failures, and a complete 205-peer pagination boundary.

MySQL suite (`tests/mysql_integration_test.py`): **15/15 passed in 4.731 seconds** against a fresh MySQL 8.4 container. The six personal-address-book tests mirror the SQLite contracts and include direct SQL JSON/value/count assertions. Initialization assertions verify schema v5, all 18 tables, and one `utf8mb4_unicode_ci` collation.

- 18 tables created and normalized to `utf8mb4_unicode_ci`.
- Address-book Unicode JSON value and tag count.
- Personal peer rename and tag rename/color, then deletion counts.
- Deployment UUID/payload, sysinfo hostname, audit nonce deduplication.
- Audit note, switch signature, two binary chunks and exact 14-byte total.
- User password hash, rename, enabled state, auth version and soft-delete timestamp.
- Device deployment/report row counts after Web removal.
- Unknown heartbeat insertion and retained heartbeat JSON/version.
- Presence timestamps around the 20-second and 90-second boundaries.
- Alias values in personal profile JSON, compatibility peer columns, and exact legacy address-book JSON.
- Alias clear semantics, preservation of unknown JSON fields, and client API readback.
- Current-user address-book isolation and legacy/current merge precedence.
- Three-store peer creation/update/deletion while retaining the device report.
- Exact tag rename/removal without rewriting similar tags or note text.
- Administrator-scoped favorite idempotence and per-user deletion behavior.
- Exact 205-peer address-book pagination and persisted row count.

## Browser E2E

`tests/e2e/admin.spec.js`: **8/8 real Chromium workflows passed in 20.0 seconds** against the fresh MySQL stack.

- Public home and old `/admin` fallback.
- Custom management path login.
- User create, search, rename, disable and delete.
- Heartbeat-only client discovery without deployment metadata.
- Client page navigation, search, online filter, labelled-only filter and summary visibility.
- Alias editing, API value readback, sync feedback and confirmed removal.
- Five-second automatic refresh and pause while the document is hidden.
- Complete multi-page loading verified with 205 matching clients, including visibility of the 205th row and an exact total of 205.
- No page or console errors.
- Client management at mobile viewport `390x844` with no horizontal overflow.
- Personal address-book modal cancel, peer add/edit/delete, tag add/delete, favorite toggle/filter and destructive confirmation.
- The explicit Web-favorite versus RustDesk-native-favorite boundary copy.
- Personal address-book mobile CRUD at `390x844` with no page overflow or console errors.
- All 205 matching address-book peers paginated through the rendered fifth page.

The interactive browser surfaces reported `Browser is not available` for both the in-app browser and Chrome. The checked-in Playwright Chromium suite still exercised the real rendered pages; desktop and mobile failure screenshots were also visually inspected while correcting selectors.

## Container verification

- Docker image `rustdesk-api:validation` built successfully from PHP 8.3 Alpine.
- The earlier `sqlite3` extension build error was fixed by compiling only `mysqli` and `pdo_mysql`; the base image already supplies PDO SQLite.
- SQLite container on host test port `17001` served internal port `80`, initialized an administrator, and retained the database across restart (`1` row before and after).
- MySQL API and MySQL 8.4 ran as separate containers on an isolated Docker network. Host test port `17004` mapped to container port `80` and passed HTTP, SQL and browser tests.
- Initialization created schema v5 and 18 tables without creating a default administrator; `manage.php --init-admin` created the first administrator from stdin. Repeating `--init-admin=admin` exited nonzero with `Username exists` and preserved the existing password.
- A forced v4/mixed-collation fixture automatically upgraded to v5 and normalized all 18 tables to `utf8mb4_unicode_ci` on application restart.
- MySQL persistence was checked across database and API container restarts after all final tests. The direct SQL snapshot stayed exactly `users=4 | device_reports=214 | profile_peers=417 | record_chunks=2 | record_bytes=14` before and after restart.
- The configured CSS route returned `200 text/css`, and an internal-container request to `http://127.0.0.1/` proved the service listens on port `80`.
- A real four-table legacy SQLite fixture migrated to schema v5 with its users, peers and token preserved.
- Required production mapping is present in both Compose files as `7000:80`.

Host port `7000` could not be bound on this Mac because macOS `ControlCenter`/AirPlay already listens on `*:7000` and returns `Server: AirTunes/980.77.5`. Equivalent container behavior was therefore verified on `17000`, `17001`, and the fresh final stack at `17004`; internal port remained `80`.

## Files changed

- `sqlite/index.php`: shared route tree and configurable management routing.
- `sqlite/lib.php`: PDO SQLite/MySQL storage, schema and migrations.
- `sqlite/manage.php`: PDO-compatible administration CLI.
- `sqlite/admin.html`, `sqlite/devices.html`, `sqlite/home.html`, `sqlite/app.css`: management and public pages plus their shared design system.
- `mysql/index.php`: compatibility entry to the shared application.
- `docker-compose.yaml`, `docker-compose.mysql.yaml`, `Dockerfile`, `.env.example`: deployment.
- `tests/integration_test.py`, `tests/mysql_integration_test.py`, `tests/e2e/admin.spec.js`: automated verification.
- `playwright.config.js`: repeatable browser test configuration.

## Deliberate limits

- OIDC endpoints return `404` until a provider is configured.
- Shared address-book reads exist, but no shared-book ACL administration UI exists.
- Device-group compatibility returns an empty collection; group/role/policy management is not implemented.
- Record chunks are stored, but playback, retention and quota jobs are not implemented.
- sysinfo/heartbeat are client-reported telemetry and are not a device identity proof.
- hbbs-only clients are not visible unless an explicit hbbs event/data integration is added; RustDesk OSS hbbs has no standard external API exposing its in-memory client registry.
