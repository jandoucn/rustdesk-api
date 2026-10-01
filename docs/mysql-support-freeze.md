# MySQL support freeze

## Current decision

As of 2026-10-01, SQLite is the only active production, development and release backend for this repository. MySQL support is frozen: existing implementation, installer assets and regression tests remain in the repository as a historical compatibility snapshot, but new features do not require MySQL implementation or verification.

Do not run `tests/mysql_integration_test.py`, create MySQL containers, or block a release on MySQL while this freeze is active. Do not delete the frozen implementation. Reactivating MySQL requires an explicit product decision followed by a fresh parity audit against the current SQLite behavior.

## Last supported baseline

- Repository: `jandoucn/rustdesk-api`
- Last release with required SQLite/MySQL parity: `v0.1.26`
- Commit: `d255f0066be4a2f7f7d3e260e4543436e73d7683`
- Published: 2026-10-01 12:25:49 UTC
- Release: <https://github.com/jandoucn/rustdesk-api/releases/tag/v0.1.26>
- GitHub Actions run: <https://github.com/jandoucn/rustdesk-api/actions/runs/36861593321>
- Run conclusion: successful ACR API image, provisioner/installer image and GitHub Release jobs
- Last recorded real MySQL gate before the freeze: MySQL 8.4 integration suite, 32 tests passed during the `v0.1.26` delivery cycle

At that baseline, the shared PHP application supported MySQL 8.4 for administrator sessions and users, client heartbeat/sysinfo inventory, device deployment identity, personal address books and tags, aliases and favorites, audit/record data, update manifests and policies, one-shot `check|install` commands, update events, batch device removal, web setup/migration and container restart persistence.

## Frozen boundary

Commits after `d255f0066be4a2f7f7d3e260e4543436e73d7683` are SQLite-first and may change shared PHP code or schemas without a corresponding MySQL validation run. Presence of MySQL code, Compose templates or old tests after this point does not claim current parity or production readiness.

The 2026-10-01 update-command authentication change introduced SQLite schema v14 and the `device_update_keys` table after the freeze decision. MySQL remains on schema v13 and does not receive this table or heartbeat-based key enrollment. MySQL behavior for this and later changes is unverified and outside the active support contract.

## Reactivation checklist

Before advertising MySQL support again:

1. Diff all behavior and schema changes since commit `d255f0066be4a2f7f7d3e260e4543436e73d7683`.
2. Reconcile migrations, collations, composite device identity and transaction semantics with SQLite.
3. Run the complete legacy migration and CRUD suite against a fresh supported MySQL container.
4. Add direct SQL assertions for every post-freeze field and workflow.
5. Run the full Playwright suite against the MySQL deployment, including desktop and 390px viewports.
6. Verify container restart persistence, backup/restore and installer flows.
7. Update this document with the new supported commit, release, workflow run and test counts.
