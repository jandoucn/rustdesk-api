# Client management contract

Updated: 2026-09-29

## RustDesk client source of truth

The authoritative RustDesk client implementation for this project is the locally modified repository at `/Users/olly/github/rustdesk`. Do not infer client behavior from the upstream RustDesk repository, release documentation, or an unmodified client when this local source tree is available.

Before changing API routes, request/response fields, heartbeat, sysinfo, address-book synchronization, device identity, client commands or compatibility behavior:

1. inspect the corresponding implementation in `/Users/olly/github/rustdesk`;
2. treat the local field names, timing, fallback behavior and configuration semantics as the current contract;
3. identify whether the change belongs in `rustdesk-api`, the local RustDesk client, or both;
4. test both repositories when the behavior crosses the client/API boundary;
5. document any intentional divergence from the local client before release.

Current client sysinfo fields must be derived from `src/common.rs::get_sysinfo` and the upload flow in `src/hbbs_http/sync.rs`, rather than assumed from the Web console schema.

## Product objective

The management console maintains an operational inventory of every RustDesk client that reports to this API through `/api/heartbeat` or `/api/sysinfo`. A deployment record is optional metadata and must not be required for a device to appear.

RustDesk OSS `hbbs` does not expose a supported external API for enumerating its complete in-memory registry. The console therefore reports API-observed clients. A client that only connects to `hbbs` and never reports to this API is outside this inventory until an explicit `hbbs` event integration is implemented and tested.

## Data contract

Each `(id, uuid)` report stores:

- the latest sysinfo JSON and observation time;
- the latest heartbeat JSON and heartbeat time;
- optional deployment metadata;
- no global alias field.

An unknown heartbeat creates a minimal report row and returns `{"sysinfo":true}`. A later sysinfo upload enriches the same row without deleting heartbeat data.

Presence is derived from `last_heartbeat`:

| Age | State |
| --- | --- |
| `0-20s` | `online` |
| `21-90s` | `recent` |
| `>90s` | `offline` |
| No heartbeat | `unreported` |

The page polls every five seconds while visible and pauses while hidden. This is near-real-time heartbeat presence, not a strong connection guarantee.

## Alias contract

Alias is scoped to the authenticated administrator's personal address book. Editing an alias must update, in one transaction:

1. `ab_profile_peers.payload.alias` for the personal profile;
2. the matching `rustdesk_peers.alias` compatibility row;
3. the exact legacy `address_books.payload` peer entry.

Unknown fields, tags, peer metadata and other address-book entries must be preserved. Empty alias means unlabelled. The RustDesk client receives the value on its next address-book pull.

## Management API

`GET ${RUSTDESK_ADMIN_PATH}/api/devices`

- Supports search, presence filter, labelled-only filter and pagination.
- Returns report-backed and deployment-only rows.
- Returns summary counts for total, online, recent, offline, unreported and labelled devices.

`PATCH ${RUSTDESK_ADMIN_PATH}/api/devices/{id}/alias?uuid={uuid}`

- Requires an authenticated administrator session and CSRF token.
- Accepts `{ "alias": "..." }`, including an empty string to clear it.
- Returns the persisted alias and `sync: "next_address_book_pull"`.

`DELETE ${RUSTDESK_ADMIN_PATH}/api/devices/{id}?uuid={uuid}`

- Requires confirmation in the UI and CSRF protection.
- Removes report and deployment records for the selected `(id, uuid)` only.
- A legacy request without `uuid` is accepted only when the ID resolves to exactly one UUID; ambiguous IDs return `409`.

`DELETE ${RUSTDESK_ADMIN_PATH}/api/devices` accepts `devices: [{id,uuid}]` and removes the exact selected identities in one transaction. If any selected device is still referenced by an address book, the whole batch is rejected with `409` and no device is removed.

## Update-control contract

Every update identity uses the reported raw `client_id` plus the device's real UUID. Exact identity wins. The legacy `client_id=RustDesk Yan` compatibility path may resolve only when that UUID identifies exactly one reported device.

The policy response, update-check response and `update-policy` SSE event expose:

- `enable_check_update` and `allow_auto_update`;
- `enable_scheduled_update`, default `false`;
- `scheduled_update_interval_hours`, default `5`, accepted range `1..168`.
- `update_policy_revision`, reported by the client with the four runtime settings above. A report may initialize server state; after that, only a report matching the current revision can reconcile a local client-side change, so stale telemetry cannot overwrite a newer administrator policy.

`PATCH ${RUSTDESK_ADMIN_PATH}/api/update/policies/{id}` changes one device. `PATCH ${RUSTDESK_ADMIN_PATH}/api/update/policies/batch` accepts either `devices: [{id,uuid}]` or `all: true` for scheduled-check policy changes. Every changed device increments its `policy_revision`, so a connected SSE client receives the change within the current stream polling window.

`POST ${RUSTDESK_ADMIN_PATH}/api/update/commands/{id}` and `/api/update/commands/batch` accept `action: check|install`. `check` asks the client to check and present its normal confirmation flow; `install` is the server-forced install command. Publishing a new manifest creates one deduplicated, version-locked `check` command for each compatible known client. An idempotent repeat publication creates no duplicate commands.

The device details dialog polls command and event state while it is open. Its terminal-style log shows the real client-reported stages and the `from_version/from_build_seq` to `to_version/to_build_seq` transition; it does not invent byte or percentage progress when the client has not reported it. Event reads for one device are scoped by the exact `id` and `uuid` pair. The same polling cycle uses the targeted `GET /admin/api/devices/{id}?uuid={uuid}` snapshot, so a newly reported build identity appears without closing the dialog and without rebuilding the full inventory.

After a successful SQLite-backed install command, heartbeat requests a fresh sysinfo report while the inventory build remains older than the command target. The request stops once sysinfo reports the target or a newer version/build. This also covers clients that reconnect after being offline; heartbeat itself remains presence-only and is never treated as a source of build identity.

The public update-policy stream is owned by the asynchronous broker. It supports signed devices and the legacy unsigned fallback used by compatible RustDesk clients; authenticated commands remain limited to signed streams. The broker reserves most long-poll capacity for signed devices, caps streams globally and per device identity, batches SQLite snapshots below the PHP endpoint limit, and never occupies PHP-FPM workers for the lifetime of a client connection. Unsigned admission and capacity rejection do not call PHP or SQLite; an overloaded client receives `503` with `Retry-After` and reconnects later.

Sysinfo inventory does not guarantee `package_kind`. When it is absent, one-shot command creation may use product, edition, platform and architecture to lock a release only if that release contains every supported package kind for the platform. Otherwise a check command remains targetless and install waits until a signed check has persisted the exact package identity. The client supplies the exact signed `target_key` and `package_kind` in `/rd/update/v1/check`; that request remains the authority for choosing EXE versus MSI and the corresponding download asset.

When a client already has the matching or a newer release, `/rd/update/v1/check` still returns `target_version` and `target_build_seq` for display, but omits `url`, `manifest_url` and `manifest` installation payloads.

## UI contract

- Dense operations layout with shared navigation and a compact statistics strip.
- Search by device ID, UUID, hostname, username or alias.
- Filter by presence and optionally show only labelled clients.
- Status uses text and an indicator dot; color alone is insufficient.
- Alias editing has explicit save/cancel/error/success states.
- Refresh preserves active filters and does not replace the page.
- The network cell shows GeoLite location below the public IP. Mainland China omits the country name; foreign locations retain it; duplicate region/city names appear once.
- The inventory row shows the RustDesk device ID but not the internal UUID. UUID remains available in the details dialog and API because `(id, uuid)` is the storage identity.
- `desktop` distribution is presented as `安装版`. Device, release and network typography must preserve a clear primary/secondary hierarchy instead of rendering every value at the same weight.
- Existing reports that contain a valid public IP but predate GeoLite enrichment receive region and timezone data when the inventory is read; the list and details dialog use the same Geo object.
- The Linux Engine installer forwards `RUSTDESK_TRUSTED_PROXY_IPS`. When the variable is unset, it discovers and uses the current bridge network's exact gateway IP; an explicitly empty value disables forwarded-header trust. Docker Desktop, rootless Docker and direct Compose deployments must set the observed proxy source IP or a deliberately chosen CIDR explicitly.
- `X-Forwarded-For` is read only when the direct peer matches a trusted proxy rule. The chain is peeled from right to left across trusted hops; `X-Real-IP` is only a fallback when no valid forwarded chain exists. Only globally reachable unicast addresses suitable for GeoIP are stored; private, shared, protocol-assignment, documentation, benchmarking, multicast and reserved ranges remain unknown.
- The image contains a GeoLite2 seed database. On first start it is copied to `/var/www/data/GeoLite2-City.mmdb` in the persistent data volume; existing volume data is never overwritten by an image upgrade. `RUSTDESK_GEOIP_SOURCE` imports a custom MMDB into that same persistent path.
- Layouts at 320, 390, 768, 1024 and 1440 pixels have no page-level horizontal overflow.

## Required tests

Every client-management change must prove:

1. Unknown heartbeat insertion and later sysinfo enrichment.
2. Presence boundary behavior and summary counts.
3. Report-only and deployment-only inventory behavior.
4. Search/filter/pagination behavior.
5. Alias set, change and clear through the management API.
6. Exact alias consistency in all three address-book stores and the RustDesk client API response.
7. Authentication, CSRF and validation failures.
8. Real SQLite migration from a legacy fixture.
9. Real MySQL 8.4 CRUD with direct SQL value/count/JSON assertions.
10. Desktop and mobile Playwright workflows, refresh behavior, errors, confirmation and overflow.
11. Container port 80, configured management path, public fallback and restart persistence.

## Personal address-book management

The management page at `${RUSTDESK_ADMIN_PATH}/address-book` operates on the signed-in administrator's personal address book only. The list merges legacy `rustdesk_peers`/`address_books.payload` data with current `ab_profile_peers` data. When both stores contain the same ID, the personal-profile payload has field priority; a row that exists in only one store still appears.

Peer create, update and delete are transactional across:

1. `ab_profile_peers` for the current personal profile;
2. `rustdesk_peers` for compatibility clients;
3. the exact peer array inside `address_books.payload`.

Unknown peer and top-level JSON fields survive updates. Removing a contact does not remove that client's `device_reports` or `device_deployments` row. RustDesk old `/api/ab` and current `/api/ab/peers` reads must return the same updated contact values.

Tags have a name and color. Rename/delete changes exact tag array members in all three stores and leaves similar names, such as `ops-prod` when renaming `ops`, and free-text notes untouched.

`admin_peer_favorites` is Web-console metadata scoped by administrator UID. Favorite writes are idempotent. The UI must explicitly say that a background favorite is not the RustDesk client's native/local favorite and is not synchronized as one.

Management API routes, relative to `${RUSTDESK_ADMIN_PATH}/api/address-book`, are:

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/` | Merged current-user peers, tags, summary, filters and pagination |
| POST | `/peers` | Add a peer to all three address-book stores |
| PATCH/DELETE | `/peers/{id}` | Update or remove a peer without deleting the device inventory |
| POST | `/tags` | Add a tag |
| PATCH/DELETE | `/tags/{name}` | Exact rename/update or removal |
| PATCH | `/favorites/{id}` | Set `{ "favorite": true|false }` idempotently |

All routes require an administrator session. Mutations additionally require the session CSRF token. Required regression coverage includes user isolation, merge precedence, three-store real CRUD and unknown-field preservation, old/new RustDesk API readback, tag substring collisions, favorite isolation/idempotence/link cleanup, invalid/auth/CSRF cases, and a 205-peer final-page boundary on SQLite, MySQL and the rendered page.
