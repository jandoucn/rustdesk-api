# Web Installer Design

## Goal

Provide a Linux-first browser installation flow. After starting a small bootstrap stack, the operator completes all configuration in a Web wizard. The wizard creates the selected database stack, initializes the schema and administrator, verifies the deployment, then redirects to the management console.

## User Flow

1. Open the static deployment-command page hosted on any public HTTPS site.
2. Enter the GitHub username, classic PAT, external port and `install.sh` URL. The page generates the command locally without submitting credentials.
3. Run the generated command on the Linux server. It installs/checks Docker, authenticates to private GHCR and starts the API plus temporary provisioner.
4. Open the printed `/setup` URL.
5. Select SQLite, managed MySQL 8.4, or an existing MySQL instance.
6. Configure the management path and initial administrator.
7. Review the settings and initialize.
8. On success, enter the authenticated management console.

The installation page is responsive at 390px and desktop widths, uses the existing visual system, exposes accessible progress text, and never requires terminal commands after the bootstrap service is running.

## Bootstrap Architecture

`installer/install.sh` starts the production API and a temporary internal provisioner on a private Docker network. The API serves the setup UI. The provisioner mounts:

- the Docker socket during installation only;
- a dedicated installer-state volume.

The provisioner API is protected by a random secret shared only through container environment variables and has no published host port. After successful initialization the API calls its completion endpoint, which deletes temporary secret state and removes the provisioner container. The production API container never receives the Docker socket.

SQLite uses the API persistent volume and does not create another container. Managed MySQL asks the provisioner to create a MySQL 8.4 container, persistent volume, internal-only network connection and randomly generated database credentials. Existing MySQL is connected directly by the API.

## Installation State Machine

States are persisted as JSON with atomic file replacement:

`ready -> validating -> provisioning -> initializing -> verifying -> complete`

Failure moves to `failed` with a safe user-facing message and retained structured diagnostics. Retry resumes from validation after removing only installer-owned failed containers; existing successful production data is never deleted.

Only one installation operation may run at a time. Every request carries the setup token and mutations also carry CSRF protection. Passwords and generated database secrets are never returned by status endpoints or written to logs.

## Database Defaults

SQLite defaults:

- database path: `/var/www/data/rustdesk.db`;
- persistent named volume;
- schema migration performed by the application.

MySQL defaults:

- image: `mysql:8.4`;
- database/user: `rustdesk`;
- internal port: `3306`, not published to the host;
- random application and root passwords;
- `utf8mb4_unicode_ci` schema v5;
- health-gated API startup.

## Validation

Before provisioning, validate:

- Docker daemon and Compose availability;
- port availability;
- management path format and reserved-path exclusions;
- administrator username and 1-72 byte password;
- writable deployment/state directories;
- project-name and volume-name safety.

After provisioning, verify:

- expected containers are healthy;
- public home and configured management path respond;
- `/admin` remains a public fallback;
- schema version is 5;
- exactly one requested administrator exists and its password verifies;
- SQLite or MySQL persistence survives a container restart.

## Testing

- Unit tests for validation, secret redaction, state transitions and generated Compose.
- SQLite installer integration against a fresh installer-owned project.
- MySQL 8.4 installer integration against a fresh installer-owned project with direct SQL assertions.
- Playwright desktop and 390px flows for both database choices, validation errors, progress, completion and repeat-install lockout.
- Container assertions for internal port 80, configured management path, public fallback and restart persistence.

## Stop Condition

The feature is complete when a fresh Linux host with Docker can start the bootstrap stack, finish either SQLite or MySQL installation entirely through the browser, enter the generated management URL, and repeat the automated verification without leaked secrets or manual database/container commands.
