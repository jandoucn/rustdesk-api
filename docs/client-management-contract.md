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

`PATCH ${RUSTDESK_ADMIN_PATH}/api/devices/{id}/alias`

- Requires an authenticated administrator session and CSRF token.
- Accepts `{ "alias": "..." }`, including an empty string to clear it.
- Returns the persisted alias and `sync: "next_address_book_pull"`.

`DELETE ${RUSTDESK_ADMIN_PATH}/api/devices/{id}`

- Requires confirmation in the UI and CSRF protection.
- Removes report and deployment records for the selected ID.

## UI contract

- Dense operations layout with shared navigation and a compact statistics strip.
- Search by device ID, UUID, hostname, username or alias.
- Filter by presence and optionally show only labelled clients.
- Status uses text and an indicator dot; color alone is insufficient.
- Alias editing has explicit save/cancel/error/success states.
- Refresh preserves active filters and does not replace the page.
- Desktop and 320/390px mobile layouts have no page-level horizontal overflow.

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
