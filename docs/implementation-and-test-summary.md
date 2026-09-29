# Implementation and test summary

Updated: 2026-09-29

## Delivered architecture

- One PHP route tree and one Web UI for SQLite and MySQL.
- PDO storage abstraction with parameter binding, transactions, upsert and insert-ignore helpers.
- SQLite legacy migration with verified pre-migration backup.
- MySQL 8.4 schema: 18 InnoDB tables; ordinary text uses `utf8mb4_unicode_ci`, while device `id`/`uuid` identity columns use case-sensitive `utf8mb4_bin`. There are no hard-coded credentials or default administrators.
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
| PATCH | `/devices/{id}/alias?uuid={uuid}` | Update the current administrator's personal address-book alias after exact device validation |
| DELETE | `/devices/{id}?uuid={uuid}` | Remove the exact deployment and report records |
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

The SQLite integration suite passed **29 tests with 1 GeoLite-only test skipped** in the host PHP runtime. The suite migrates a real legacy database to schema v8, rejects duplicate legacy administrator names without publishing configuration, verifies recursive unknown-JSON preservation, `(id, uuid)` isolation including case variants, wrong-UUID rejection, exact deletion, address-book behavior and direct SQL state. A PHP 8.3 container with the MaxMind extension separately passed a real MMDB lookup for `81.2.69.160`, persisted `GB / England / London`, then changed the public IP to `8.8.8.8` and atomically cleared the stale location.

MySQL suite (`tests/mysql_integration_test.py`): **20/20 passed** against a fresh MySQL 8.4 container. It includes real v5/v6-style database migration to v8, duplicate legacy administrator rejection without mutation, direct SQL assertions for the case-sensitive composite deployment key, same-ID/different-UUID preservation, wrong-UUID rejection, recursive JSON preservation, atomic network/Geo state, exact deletion, runtime/network JSON, and the existing address-book matrix.

- 18 tables created with `utf8mb4_unicode_ci` defaults; `device_reports` and `device_deployments` use `utf8mb4_bin` on `id` and `uuid`.
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

`tests/e2e/admin.spec.js`: the final fresh SQLite container passed **16/16** real Chromium workflows. Geo formatting is driven by persisted `network_payload` data and checked across direct SQL, API output, list rendering and the detail dialog. Composite identities containing delimiter characters are also verified across refresh.

- Public home and old `/admin` fallback.
- Custom management path login.
- User create, search, rename, disable and delete.
- Heartbeat-only client discovery without deployment metadata.
- Client page navigation, search, online filter, labelled-only filter and summary visibility.
- Alias editing, API value readback, sync feedback and confirmed removal.
- Five-second automatic refresh and pause while the document is hidden.
- A changed row in the middle of a long device list retains its composite identity, keyboard focus and scroll anchor across automatic refresh; the refreshed details dialog shows the new persisted value.
- Runtime, network, version and device details are rendered and checked against API values.
- The network cell renders GeoLite location below the public IP: China omits the country name, foreign addresses retain it, and duplicate province/city names are collapsed.
- Complete multi-page loading verified with 205 matching clients, including visibility of the 205th row and an exact total of 205.
- No page or console errors.
- Client management at `320`, `390`, `768`, `1024` and `1440` widths with no horizontal overflow.
- Personal address-book modal cancel, peer add/edit/delete, tag add/delete, favorite toggle/filter and destructive confirmation.
- The explicit Web-favorite versus RustDesk-native-favorite boundary copy.
- Personal address-book mobile CRUD at `390x844` with no page overflow or console errors.
- All 205 matching address-book peers paginated through the rendered fifth page.

The interactive browser surface was unavailable because the local Codex browser bridge rejected its current API-key authentication mode. The checked-in Playwright Chromium suite still exercised the real rendered pages across both databases and all required breakpoints.

## Container verification

- Docker image `rustdesk-api:inventory-v8-final` built successfully from PHP 8.3 Alpine with `maxminddb` and MySQL PDO extensions.
- The earlier `sqlite3` extension build error was fixed by compiling only `mysqli` and `pdo_mysql`; the base image already supplies PDO SQLite.
- The final SQLite container on host test port `17116` served internal port `80`, initialized an administrator, and retained `users=2 | device_reports=258 | profile_peers=206` across restart.
- The final MySQL API and MySQL 8.4 ran as separate containers on an isolated Docker network. Host test port `17117` mapped to container port `80` and passed HTTP, SQL and browser tests.
- Initialization created schema v8 and 18 tables without a default administrator. `/setup` creates a new administrator or promotes an existing legacy user in place with the newly entered password, preserving that user's ID and data while revoking old sessions.
- A real v5 legacy MySQL fixture with the old single-column deployment key upgraded to v8, retained its report/deployment JSON and timestamps, changed the deployment key to `(id, uuid)`, and converted device identity columns to `utf8mb4_bin`. The version bump also forces already-created v7 databases through the collation repair.
- MySQL persistence was checked across database and API container restarts after all final tests. The direct SQL snapshot stayed exactly `users=4 | device_reports=268 | profile_peers=417 | record_chunks=2 | record_bytes=14` before and after restart.
- The configured CSS route returned `200 text/css`, and an internal-container request to `http://127.0.0.1/` proved the service listens on port `80`.
- A real production SQLite snapshot copy migrated to schema v8 with `PRAGMA integrity_check=ok`, two users and five legacy peers preserved. The source snapshot SHA-256 remained `a2a26ac70a6cffd20532b1ef71d13e7ffd0aa49349812bc9018c9e26291a5108`.
- Required production mapping is present in both Compose files as `7000:80`.

Host port `7000` could not be bound on this Mac because macOS `ControlCenter`/AirPlay already listens on `*:7000` and returns `Server: AirTunes/980.77.5`. Equivalent final container behavior was therefore verified on `17116`, `17117`, and the GeoLite stack at `17118`; internal port remained `80`.

## Files changed

- `sqlite/index.php`: shared route tree and configurable management routing.
- `sqlite/lib.php`: PDO SQLite/MySQL storage, schema and migrations.
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
