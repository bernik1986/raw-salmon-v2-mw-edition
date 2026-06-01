# RAW SALMON V2.0 Roadmap

This roadmap is the working source of truth for implementation status. It was initialized after a local audit of commit `1244811` on 2026-05-31.

Status markers:

- `[x]` implemented in the current baseline
- `[~]` partially implemented or requiring hardening
- `[ ]` planned

## Definition of Done

Every feature or behavior change must include:

1. The implementation.
2. Relevant automated tests in `tests/run.php` and/or `tests/contract_test.py`.
3. A roadmap status update and a short entry in the Change Log below.
4. A clean run of:

```bash
php tests/run.php
python -m pytest tests/contract_test.py -q
```

Documentation-only changes may skip new tests, but the existing checks should still run.

## Implemented Baseline

- [x] PHP 8.1 and SQLite installer with generated local encryption key and cron token.
- [x] Login/logout, session expiration, CSRF protection, Admin and User roles, and Admin user management.
- [x] Encrypted Mailgun API key and webhook signing key storage.
- [x] US and EU Mailgun endpoints, multi-domain configuration, default domain selection, and connection tests.
- [x] Per-user recipient whitelist.
- [x] Preset create/edit, JSON validation and preview, clone, import, export, delays, generated From addresses, and recipient selection.
- [x] Preset attachment storage outside the public web root and job attachment snapshots.
- [x] Sending job confirmation, cron queue processing, cancellation, pause, resume, failed-send retry, and send logs.
- [x] Mailgun webhook signature verification, delivery event storage, queue delivery status updates, and webhook event viewer.
- [x] Dashboard counts for presets, domains, recipients, attachments, queue states, deliveries, and webhook events.
- [x] Root wrapper files and computed base URLs for shared hosting uploads at the SSL web root or inside a subdirectory.
- [x] Apache `DirectoryIndex index.php` files for opening an uploaded project folder without typing `/index.php`.
- [x] Protected local session storage under `storage/sessions/` so login does not depend on the hosting provider's PHP session directory.
- [x] PHP 7.4-compatible entry guard that routes old shared-hosting PHP versions to the installer guidance page.
- [x] Admin diagnostics page with hosting health checks and redacted Mailgun connection/send failure events.
- [x] Shared-hosting Admin password recovery using a protected, automatically rotated token in `config/local.php`.
- [x] Cron queue domain resolution with managed-domain and default-domain fallback.
- [x] PHP integration tests and Python structural contract tests.

## Partial Features

- [~] The login form displays `Remember me`, but the selected value is not used by the authentication or session code.
- [~] Presets store, import, export, and display `batch_size`, but queue scheduling and cron processing do not apply batch behavior.
- [~] Presets have an active/inactive status column, but there is no lifecycle action in the UI and job creation does not enforce that status.
- [~] Audit log storage exists and selected authentication and queue actions are recorded, but there is no audit log viewer and coverage is incomplete.
- [~] The queue and Mailgun client support `body_html`, but the preset JSON schema and UI currently create text-only messages.
- [~] Domain limits are configurable, but limit checks count all of a user's queued messages rather than usage for the selected domain.

## Planned Work

### P0: Security and Delivery Safety

- [x] Reject installer POST requests after installation so an unauthenticated repeat request cannot disclose the saved cron token.
- [ ] Claim due queue rows atomically before sending to prevent duplicate delivery when cron workers overlap.
- [ ] Recover queue rows left in `sending` state after an interrupted worker.
- [ ] Make webhook domain ownership unambiguous across users and verify that linked queue and job IDs belong to the resolved user and domain.
- [ ] Add webhook timestamp freshness and replay-protection checks.
- [ ] Add integration tests for installer lockout, queue concurrency recovery, and webhook tenant isolation.

### P1: Finish Existing Product Surfaces

- [ ] Decide whether to implement persistent `Remember me` sessions or remove the checkbox.
- [ ] Define and implement `batch_size` behavior, then add scheduling tests.
- [ ] Add preset activate/deactivate controls and enforce inactive status during job creation.
- [ ] Add an Admin audit-log viewer and record settings, domain, recipient, preset, and user-management changes.
- [ ] Decide whether HTML email authoring belongs in the product; either implement it end to end or remove unused fields.
- [ ] Enforce and test domain-scoped hourly and daily limits.

### P2: Operations and Confidence

- [ ] Add CI that runs PHP lint, `tests/run.php`, and `tests/contract_test.py`.
- [ ] Add HTTP smoke tests for install, login, primary CRUD pages, queue actions, cron authentication, and webhook responses.
- [ ] Document local XAMPP development setup and production deployment hardening.
- [ ] Add retention and cleanup rules for logs, webhook payloads, queued records, and attachment files.

## Verified Test Baseline

Verified locally on 2026-06-01:

- `C:\xampp\php\php.exe tests/run.php`: 15 runnable PHP tests passed.
- PHP lint: all tracked PHP files passed.
- `python -m pytest tests/contract_test.py -q -p no:cacheprovider`: 7 tests passed.

## Change Log

- 2026-05-31: Initialized the roadmap after syncing and auditing the repository at commit `1244811`.
- 2026-05-31: Documented the required implementation, tests, roadmap-update workflow for future work.
- 2026-05-31: Ignored runtime attachment uploads while keeping `storage/attachments/.gitkeep` tracked.
- 2026-05-31: Added shared-hosting upload support for root, subdirectory, and `public/` document-root deployments.
- 2026-05-31: Added installer hosting diagnostics, write-permission checks, PHP version guidance, and repeat-install lockout.
- 2026-05-31: Added regression tests for deployment URL handling and the shared-host upload contract.
- 2026-05-31: Added protected local PHP session storage with installer diagnostics for shared hosting reliability.
- 2026-05-31: Added Apache directory-index files for direct project-folder entry.
- 2026-05-31: Added a PHP 7.4-compatible entry guard after live hosting diagnostics showed the domain was still using PHP 7.4.33.
- 2026-05-31: Made the old-PHP guidance page return normally so browsers display the hosting-panel instruction instead of a generic HTTP 500 screen.
- 2026-06-01: Added Admin Diagnostics with hosting health checks, redacted Mailgun domain-test and failed-send events, plus regression coverage.
- 2026-06-01: Added shared-hosting Admin password recovery with a protected token, automatic token rotation, account reactivation, and regression coverage.
- 2026-06-01: Fixed cron queue sends with an empty Mailgun domain when a preset relies on the default managed domain; added regression coverage.
