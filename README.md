# RAW SALMON V2.0 MW edition

PHP 8.1 + SQLite web app for QA test email sending through Mailgun HTTP API.

Use it only for your own systems and permitted test recipients. The app enforces a per-user recipient whitelist before creating sending jobs.

## What Is Included

- Login/logout with Admin and User roles.
- Admin user management.
- Per-user Mailgun settings with encrypted API key storage.
- Region-aware Mailgun API sending for US and EU.
- Multi-domain manager with per-domain region, From name, Reply-To, test mode, limits, default/active flags, and Test Connection.
- Recipient whitelist.
- Presets with language, topic, JSON payload, From pattern, delay settings, and selected recipients.
- Preset import/export and clone.
- JSON validation and preview.
- Random From email generation using `{random}` and `{date}`.
- Preset attachments stored outside the public web root.
- Queue creation with explicit outgoing message summary.
- Cron queue sender with token protection.
- Logs for sent and failed messages.
- Admin diagnostics page with server checks and redacted Mailgun error events.
- Cancel, pause, resume jobs and retry failed emails.
- Mailgun delivery webhook endpoint with HMAC signature verification and delivery event storage.
- Expanded dashboard statistics for domains, recipients, attachments, queue states, delivery events, and webhook events.
- PHP tests plus structural contract tests.

## Install

1. Select PHP 8.1 or newer in the SSL hosting control panel.
2. Upload the project files into the SSL web root, such as `public_html/`, or upload the whole folder into a subdirectory such as `public_html/raw-salmon/`.
3. Open `https://yourdomain.com/install.php` or `https://yourdomain.com/raw-salmon/install.php`.
4. Confirm that every requirement is OK, including write access for `config/` and `storage/`, then create the first Admin user.
5. Set Mailgun settings in the app.
6. Add domains in the Domains manager and run Test Connection.
7. Add whitelist recipients.
8. Create a preset, validate JSON, preview, then create a sending job.

If Mailgun connection testing or sending fails, open `Diagnostics` as Admin. Run `Domains > Test Connection`, reload the diagnostics page, and share a screenshot of the latest event. API keys, tokens, passwords, and signatures are redacted.

If the Admin password is lost, open `https://yourdomain.com[/optional-folder]/recover-admin.php`. The page creates an `admin_recovery_token` inside the protected `config/local.php` file. Copy that token through the hosting file manager, enter the Admin email and a new password, then log in. The token is rotated after each successful reset. Never share it.

No terminal access, Composer, database setup, or document-root changes are required for the shared-hosting upload flow. Upload hidden `.htaccess` files as well: they select `index.php` and deny direct access to `app`, `config`, `storage`, and `tests` on Apache hosting. If the hosting document root can be pointed at `public/`, that is still supported. Runtime sessions are stored under the protected `storage/sessions/` directory.

If the first page reports that PHP 8.1+ is required, change the domain's PHP version in the hosting control panel and reload the page.

## Cron

Set a cron job to call:

```text
https://yourdomain.com[/optional-folder]/cron/send-queue.php?token=YOUR_CRON_TOKEN
```

The token is generated during install and stored in `config/local.php`.

## Mailgun Webhooks

Set the Webhook Signing Key in Mailgun Settings, then configure Mailgun delivery webhooks to call:

```text
https://yourdomain.com[/optional-folder]/mailgun-webhook.php
```

The app stores accepted, delivered, opened, clicked, complained, unsubscribed, temporary fail, and permanent fail events. Events sent with the app's outgoing `v:queue_id` and `v:job_id` variables are linked back to queued messages.

## Preset Import / Export

Preset exports are JSON files with this schema:

```text
mail-test-sender-preset-v1
```

Imports require recipients to already exist in the user's whitelist. Attachment metadata is exported, but binary attachment files are not embedded in the JSON export.

## Roadmap

See [ROADMAP.md](ROADMAP.md) for the verified implementation baseline, partial features, planned work, and the maintenance checklist for future changes.

## Tests

PHP test runner:

```bash
php tests/run.php
```

Python structural contract tests:

```bash
python -m pytest tests/contract_test.py -q
```

The PHP integration tests require `pdo_sqlite`.
