# RustDesk API

PHP 8.3 API and Web management service compatible with the current RustDesk client source in `/Users/olly/github/rustdesk`. SQLite and MySQL use the same route tree, pages, authentication rules, and CRUD implementation.

## Container deployment

### Browser-guided installation from private GHCR

Host the files in `installer/web/` and `installer/install.sh` on any public HTTPS site. The static deployment page asks for the GitHub username, a classic PAT with `read:packages`, the external port (default `7000`) and the `install.sh` URL. It builds the Linux command entirely in the browser; credentials are not submitted to the hosting site.

For mainland China, the generator defaults to the verified `ghfast.top` prefix for the public installation script. The generated command downloads the script to a temporary file and checks its SHA-256 before running it as root. `ghfast.top` is not a Docker Registry V2 endpoint, so private GHCR images still use authenticated `ghcr.io` pulls.

The generated command has this shape:

```sh
f=$(mktemp) && curl -fsSL \
  'https://ghfast.top/https://raw.githubusercontent.com/jandoucn/rustdesk-api/main/installer/install.sh' \
  -o "$f" && echo '<sha256>  '$f | sha256sum -c - && \
  sudo env GHCR_USERNAME='github-user' GHCR_TOKEN='classic-pat' RUSTDESK_PORT=7000 bash "$f"
```

For better shell-history hygiene, the page can generate a command without the Token. The installer then reads it without echoing through `/dev/tty`.

The script pulls these private images by default:

```text
ghcr.io/jandoucn/rustdesk-api:latest
ghcr.io/jandoucn/rustdesk-api-provisioner:latest
```

It creates the API network and persistent data volume, starts the API, and prints `http://SERVER_IP:PORT/setup`. The setup wizard then configures the management path and initial administrator:

- SQLite uses the API data volume and creates no database container.
- Managed MySQL creates an internal MySQL 8.4 container and named volume through the temporary provisioner.
- Existing MySQL uses the connection information entered in the wizard.

The production API never mounts the Docker socket. Only the temporary provisioner receives it, and the API asks that provisioner to remove itself after successful initialization.

Managed MySQL defaults to the mainland accelerator image `docker.1ms.run/mysql:8.4`. Override it at install time with `MYSQL_IMAGE=<registry>/<namespace>/mysql:8.4` when needed.

The GitHub Actions workflow `.github/workflows/ghcr.yml` publishes both images. Keep the packages private and give deployment Tokens `read:packages`; organization SSO must also authorize the Token when enabled.
Every build publishes a `commit-<short-sha>` image tag and OCI revision metadata in addition to `latest` on the default branch, so each image can be traced back to its source commit.

### Mainland China offline image transfer

When direct private-GHCR pulls are too slow, build a self-contained transfer bundle on a machine that can access GHCR. The exporter always logs in with a temporary Docker configuration and runs a fresh `docker pull --platform linux/amd64` for both `latest` images before saving them. It does not package an old local image silently.

```sh
./installer/export-offline-bundle.sh
```

The Token is read without echoing and is not placed in shell history. The output is approximately 112 MiB:

```text
dist/rustdesk-api-offline-linux-amd64-latest.tar
dist/rustdesk-api-offline-linux-amd64-latest.tar.sha256
```

Upload both files from the machine where they were generated:

```sh
scp dist/rustdesk-api-offline-linux-amd64-latest.tar* \
  root@47.100.7.221:/opt/1panel/apps/
```

Termark can transfer the same files without configuring a private key inside the source server:

```sh
termark upload <NTServer-SH-asset-id> \
  dist/rustdesk-api-offline-linux-amd64-latest.tar \
  /opt/1panel/apps/rustdesk-api-offline-linux-amd64-latest.tar
termark upload <NTServer-SH-asset-id> \
  dist/rustdesk-api-offline-linux-amd64-latest.tar.sha256 \
  /opt/1panel/apps/rustdesk-api-offline-linux-amd64-latest.tar.sha256
```

Then load and deploy on the server:

```sh
cd /opt/1panel/apps
sha256sum -c rustdesk-api-offline-linux-amd64-latest.tar.sha256
mkdir -p rustdesk-api-offline
tar -xf rustdesk-api-offline-linux-amd64-latest.tar -C rustdesk-api-offline
sudo env RUSTDESK_PORT=7000 \
  bash rustdesk-api-offline/install-offline-bundle.sh
```

The loader verifies the files, imports both images, and invokes the normal installer with `RUSTDESK_OFFLINE=1`. Database selection and administrator creation still happen in the browser. Managed MySQL continues to pull `docker.1ms.run/mysql:8.4`; SQLite needs no database image.

Do not run `scp -r /opt/1panel/apps/rustdesk-data root@47.100.7.221:/opt/1panel/apps/` from `NTServer-SH` itself. That address resolves back to the same server, the source and destination are the same path, and the server has no private key for its own public SSH endpoint.

### Compose deployment

The container always listens on port `80`; Compose publishes host port `7000`.

```sh
cp .env.example .env
# Edit RUSTDESK_ADMIN_PATH and passwords first.

# SQLite
docker compose up -d --build

# MySQL 8.4
docker compose -f docker-compose.mysql.yaml up -d --build

# Create the first administrator. The password is read from stdin.
docker compose exec -T api php /var/www/html/manage.php \
  --init-admin=admin --password-stdin
```

Open `http://host:7000/` for the public home page. The management page exists only at `RUSTDESK_ADMIN_PATH`; its default is `/ops-console`, never `/admin`. For example:

```env
RUSTDESK_ADMIN_PATH=/company-control-9f31
```

Then use `http://host:7000/company-control-9f31`. `/admin`, unknown page paths, and other paths outside the configured prefix render the public home page. Unknown `/api/*` paths still return JSON `404`.

## Database configuration

SQLite is the default:

```env
RUSTDESK_DB_DRIVER=sqlite
RUSTDESK_DB=/var/www/data/rustdesk.db
```

MySQL uses PDO and creates/migrates 18 InnoDB/utf8mb4 tables automatically. Schema v5 also normalizes existing tables to `utf8mb4_unicode_ci` so legacy and current address-book rows can be joined consistently:

```env
RUSTDESK_DB_DRIVER=mysql
RUSTDESK_DB_HOST=mysql
RUSTDESK_DB_PORT=3306
RUSTDESK_DB_NAME=rustdesk
RUSTDESK_DB_USER=rustdesk
RUSTDESK_DB_PASSWORD=replace-me
```

There is no built-in administrator or default password. Legacy `MD5(password + "rustdesk")` hashes remain readable and upgrade to `password_hash` after a successful login.

## Implemented capabilities

- Client login/currentUser/logout, users and peers.
- Legacy address book and current personal address-book peer/tag CRUD.
- Shared-profile and device-group compatibility responses.
- sysinfo, sysinfo_ver, heartbeat, deploy and CLI assignment.
- Connection/file/alarm audit, audit notes, switch-grant, record chunks.
- Admin session/CSRF, modal user CRUD, one-character-or-longer passwords, status changes and soft delete.
- Client inventory from heartbeat/sysinfo reports, search, presence filters, summary counts and removal.
- Near-real-time presence: online through 20 seconds, recently online through 90 seconds, then offline; the page refreshes every 5 seconds while visible.
- Per-administrator address-book aliases, editable in the console and delivered to RustDesk on its next address-book sync.
- Current-administrator personal address-book management: merged legacy/current peer listing, peer CRUD, exact tag CRUD, search/filter/pagination and administrator-scoped Web favorites.
- Configurable management route with old `/admin` isolation.

Client inventory includes every client that sends this API a heartbeat or sysinfo report; deployment is optional metadata. RustDesk OSS hbbs does not expose a standard external API for its in-memory connection registry, so clients that connect only to hbbs and never report to this API are not inventorially visible.

Aliases are scoped to the signed-in administrator's personal address book, not stored as a global device name. A console edit updates the personal profile, compatibility peer row, and exact legacy address-book payload in one transaction. An empty alias remains unlabelled and is never replaced with the hostname.

The management-page favorite star is separate administrator metadata. It supports quick filtering in the Web console and does not create, remove or claim to synchronize RustDesk client-native favorites.

OIDC is explicitly unavailable (`404`). Shared address-book administration, real device-group administration, policy delivery, browser remote control, and recording playback/retention are not implemented; the open-source client falls back cleanly where applicable.

## Tests

```sh
# SQLite migration and HTTP contract, 25 tests
FRANKENPHP=/tmp/rustdesk-local-runtime/frankenphp \
  python3 -m unittest -q tests.integration_test

# MySQL HTTP CRUD plus direct SQL value/count assertions, 15 tests
PYTHONPATH=. RUSTDESK_MYSQL_CONTAINER=<mysql-container> \
RUSTDESK_TEST_URL=http://127.0.0.1:17000 \
  python3 -m unittest -q tests.mysql_integration_test

# Real Chromium desktop/mobile workflows and both 205-row pagination boundaries, 8 tests
RUSTDESK_TEST_URL=http://127.0.0.1:17000 \
RUSTDESK_ADMIN_PATH=/ops-x9 npx playwright test
```

Migration, API, browser, container, and verification details are recorded in [docs/implementation-and-test-summary.md](docs/implementation-and-test-summary.md). The inventory, presence, alias, UI and mandatory test rules are fixed in [docs/client-management-contract.md](docs/client-management-contract.md) and the repository [AGENTS.md](AGENTS.md). SQLite backup and rollback instructions remain in [docs/sqlite-web-admin-migration.md](docs/sqlite-web-admin-migration.md).
