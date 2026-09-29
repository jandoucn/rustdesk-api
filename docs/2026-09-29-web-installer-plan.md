# Web Installer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Linux-first browser wizard that provisions either the SQLite or MySQL Docker stack, initializes the first administrator, verifies persistence, and redirects into the configured console.

**Architecture:** A temporary Python standard-library installer container owns setup state and talks to Docker only during installation. It generates one of two checked-in Compose templates plus a secret `.env`, runs the selected stack, invokes the existing PHP initialization CLI through container stdin, and exposes polling status to a responsive setup page.

**Tech Stack:** Python 3 standard library, Docker CLI with Compose v2, PHP 8.3 application image, MySQL 8.4, HTML/CSS/JavaScript, Python unittest, Playwright.

---

### Task 1: Installer Domain and Validation

**Files:**
- Create: `installer/installer.py`
- Create: `tests/installer_test.py`

- [ ] Write failing unit tests for SQLite/MySQL defaults, port/path/username/password validation, project-name safety, secret redaction and atomic state transitions.
- [ ] Run `python3 -m unittest -q tests.installer_test` and confirm missing-module failures.
- [ ] Implement immutable normalized install configuration, random secret generation, atomic JSON state writes and public-state redaction.
- [ ] Re-run the installer unit tests and require all assertions to pass.

### Task 2: Generated Deployment Files

**Files:**
- Create: `installer/templates/compose.sqlite.yaml`
- Create: `installer/templates/compose.mysql.yaml`
- Modify: `installer/installer.py`
- Test: `tests/installer_test.py`

- [ ] Add failing tests asserting SQLite emits only `api`, MySQL emits `api` plus a health-gated `mysql:8.4`, both publish the selected host port to internal port 80, and neither publishes MySQL port 3306.
- [ ] Add fixed Compose templates using named volumes and environment interpolation.
- [ ] Implement deployment directory creation, mode-0600 `.env` output, template selection and deterministic project naming.
- [ ] Run unit tests and `docker compose --env-file <fixture> -f <generated> config` for both fixtures.

### Task 3: Provisioning State Machine

**Files:**
- Modify: `installer/installer.py`
- Test: `tests/installer_test.py`

- [ ] Add failing tests for `ready -> validating -> provisioning -> initializing -> verifying -> complete`, single-run locking, failed-state diagnostics and completed-state mutation lockout.
- [ ] Implement a background installation worker with bounded subprocess timeouts and secret-redacted command results.
- [ ] Run Docker Compose build/up, wait for container health, pipe the administrator password to `manage.php --init-admin`, verify HTTP routes and query schema/admin state through `docker compose exec`.
- [ ] Add restart persistence verification and preserve installer-owned volumes on ordinary retries.

### Task 4: Installer HTTP API

**Files:**
- Modify: `installer/installer.py`
- Create: `installer/entrypoint.sh`
- Test: `tests/installer_test.py`

- [ ] Add failing HTTP tests for `GET /api/status`, `POST /api/install`, invalid JSON, validation errors, concurrent install rejection and post-completion lockout.
- [ ] Implement `ThreadingHTTPServer`, JSON size limits, setup-token authentication, CSRF validation, security headers and static-file delivery.
- [ ] Generate the setup token on first boot, persist only its hash, and print the one-time setup URL to container logs.
- [ ] Run unit/HTTP tests and verify responses contain no administrator or database passwords.

### Task 5: Responsive Setup Wizard

**Files:**
- Create: `installer/web/index.html`
- Create: `installer/web/setup.css`
- Create: `installer/web/setup.js`
- Test: `tests/e2e/installer.spec.js`

- [ ] Build a five-step wizard: database, connection defaults, site/admin settings, confirmation, progress/result.
- [ ] Use radios for database selection, password inputs with explicit labels, 40px controls, visible focus, textual progress and inline validation.
- [ ] Poll status during installation, render each state without exposing secrets, and construct the final console URL from the current hostname, selected port and returned admin path.
- [ ] Add desktop and 390px Playwright scenarios covering SQLite, MySQL, validation error, failure feedback, completion redirect and no horizontal overflow.

### Task 6: Bootstrap Packaging

**Files:**
- Create: `installer/Dockerfile`
- Create: `installer-compose.yaml`
- Create: `install.sh`
- Modify: `.gitignore`
- Modify: `README.md`

- [ ] Build an installer image containing Python, Docker CLI and Compose v2 without application runtime privileges after setup.
- [ ] Add a bootstrap Compose file that mounts the project directory, installer state directory and Docker socket only into the installer service.
- [ ] Add an idempotent Linux `install.sh` that checks Docker/Compose, creates state permissions, starts the bootstrap service and prints the tokenized URL.
- [ ] Document the single Linux bootstrap command, panel/Portainer import path, setup-port override and recovery procedure.

### Task 7: Real SQLite Installation

**Files:**
- Create: `tests/installer_integration_test.py`

- [ ] Start the installer on a disposable setup port and submit a SQLite installation through its HTTP API.
- [ ] Assert generated files, one API container, schema v5, requested admin password verification, configured path isolation and public fallback.
- [ ] Restart the generated API container and compare user/schema counts before and after.
- [ ] Remove only the disposable test project and volumes.

### Task 8: Real MySQL 8.4 Installation

**Files:**
- Modify: `tests/installer_integration_test.py`

- [ ] Submit MySQL installation on a separate disposable port and wait for MySQL health.
- [ ] Assert two services, unexposed port 3306, 18 `utf8mb4_unicode_ci` tables, schema v5 and the requested administrator.
- [ ] Run existing `tests.mysql_integration_test` against the generated stack and direct SQL assertions for JSON/BLOB/count values.
- [ ] Restart API and MySQL containers and compare persisted values before and after.

### Task 9: Final Verification

**Files:**
- Modify: `docs/implementation-and-test-summary.md`

- [ ] Run installer unit and integration suites, existing SQLite/MySQL suites, and all Playwright scenarios.
- [ ] Run PHP lint, Python compile, Compose config validation, Docker image builds and `git diff --check`.
- [ ] Confirm no setup token, administrator password, MySQL password or Docker socket is present in the production API container.
- [ ] Record exact results, ports, image names and remaining operational constraints in the verification summary.
