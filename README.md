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
- Cancel, pause, resume jobs and retry failed emails.
- Mailgun delivery webhook endpoint with HMAC signature verification and delivery event storage.
- Expanded dashboard statistics for domains, recipients, attachments, queue states, delivery events, and webhook events.
- PHP tests plus structural contract tests.

## Install

1. Upload the folder to hosting with PHP 8.1+.
2. Open `/install.php`.
3. Confirm requirements are OK, then create the first Admin user.
4. Set Mailgun settings in the app.
5. Add domains in the Domains manager and run Test Connection.
6. Add whitelist recipients.
7. Create a preset, validate JSON, preview, then create a sending job.

If the hosting document root can be pointed at `public/`, use that. If not, the root wrapper files still allow the app to run from the uploaded folder root. Apache `.htaccess` files deny direct access to `app`, `config`, `storage`, and `tests`.

## Cron

Set a cron job to call:

```text
https://yourdomain.com/cron/send-queue.php?token=YOUR_CRON_TOKEN
```

The token is generated during install and stored in `config/local.php`.

## Mailgun Webhooks

Set the Webhook Signing Key in Mailgun Settings, then configure Mailgun delivery webhooks to call:

```text
https://yourdomain.com/mailgun-webhook.php
```

The app stores accepted, delivered, opened, clicked, complained, unsubscribed, temporary fail, and permanent fail events. Events sent with the app's outgoing `v:queue_id` and `v:job_id` variables are linked back to queued messages.

## Preset Import / Export

Preset exports are JSON files with this schema:

```text
mail-test-sender-preset-v1
```

Imports require recipients to already exist in the user's whitelist. Attachment metadata is exported, but binary attachment files are not embedded in the JSON export.

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
