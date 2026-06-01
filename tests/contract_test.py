import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_required_structure_exists():
    required = [
        ".htaccess",
        "install.php",
        "public/.htaccess",
        "public/login.php",
        "public/dashboard.php",
        "public/diagnostics.php",
        "public/recover-admin.php",
        "public/users.php",
        "public/mailgun-settings.php",
        "public/domains.php",
        "public/recipients.php",
        "public/presets.php",
        "public/preset-edit.php",
        "public/preset-export.php",
        "public/send-job.php",
        "public/queue.php",
        "public/logs.php",
        "public/mailgun-webhook.php",
        "public/webhook-events.php",
        "cron/send-queue.php",
        "app/Auth.php",
        "app/AdminRecoveryService.php",
        "app/AppLog.php",
        "app/Security.php",
        "app/MailgunClient.php",
        "app/DomainService.php",
        "app/AttachmentService.php",
        "app/QueueService.php",
        "app/WebhookService.php",
        "app/JsonValidator.php",
        "app/FromGenerator.php",
        "storage/sessions/.gitkeep",
    ]
    missing = [path for path in required if not (ROOT / path).is_file()]
    assert not missing


def test_database_schema_has_planned_tables():
    database = read("app/Database.php")
    for table in [
        "users",
        "mailgun_settings",
        "mailgun_domains",
        "recipients",
        "presets",
        "preset_attachments",
        "sending_jobs",
        "job_attachments",
        "email_queue",
        "email_logs",
        "mailgun_webhook_events",
    ]:
        assert f"CREATE TABLE IF NOT EXISTS {table}" in database


def test_mailgun_contract_is_present():
    client = read("app/MailgunClient.php")
    assert "https://api.mailgun.net" in client
    assert "https://api.eu.mailgun.net" in client
    assert "/v3/" in client
    assert "/v4/domains/" in client
    assert "/messages" in client
    assert "h:Reply-To" in client
    assert "o:testmode" in client
    assert "v:queue_id" in client


def test_safety_contracts_are_present():
    security = read("app/Security.php")
    queue = read("app/QueueService.php")
    preset = read("app/PresetService.php")
    webhook = read("app/WebhookService.php")
    assert "password_hash" in read("app/UserService.php")
    assert "aes-256-gcm" in security
    assert "csrfToken" in security
    assert "Daily limit would be exceeded" in queue
    assert "Hourly limit would be exceeded" in queue
    assert "paused_at IS NULL" in queue
    assert "fallback.is_default DESC" in queue
    assert "active whitelist recipients" in preset
    assert "hash_hmac('sha256'" in webhook
    assert "signature_valid" in webhook


def test_shared_host_upload_contract_is_present():
    wrappers = [
        "dashboard.php",
        "diagnostics.php",
        "domains.php",
        "login.php",
        "logout.php",
        "logs.php",
        "mailgun-settings.php",
        "mailgun-webhook.php",
        "preset-edit.php",
        "preset-export.php",
        "presets.php",
        "queue.php",
        "recover-admin.php",
        "recipients.php",
        "send-job.php",
        "users.php",
        "webhook-events.php",
    ]
    missing = [path for path in wrappers if not (ROOT / path).is_file()]
    assert not missing

    helpers = read("app/helpers.php")
    assert "function app_base_url()" in helpers
    assert "function public_base_url()" in helpers
    assert "header('Location: ' . url($path))" in helpers

    rendered = list((ROOT / "public").glob("*.php")) + [
        ROOT / "app/View.php",
        ROOT / "install.php",
    ]
    for path in rendered:
        assert not re.search(r'(?:href|action|formaction)="/', path.read_text(encoding="utf-8")), path

    installer = read("install.php")
    bootstrap = read("app/bootstrap.php")
    config = read("config/config.php")
    root_index = read("index.php")
    public_index = read("public/index.php")
    assert "DirectoryIndex index.php" in read(".htaccess")
    assert "DirectoryIndex index.php" in read("public/.htaccess")
    assert "PHP_VERSION_ID < 80100" in installer
    assert "http_response_code(500);" not in installer.split("require __DIR__ . '/app/bootstrap.php';", 1)[0]
    assert "Writable config directory" in installer
    assert "Writable storage directory" in installer
    assert "Writable session storage" in installer
    assert "session_storage_path" in config
    assert "session_save_path" in bootstrap
    assert root_index.index("PHP_VERSION_ID < 80100") < root_index.index("app/bootstrap.php")
    assert public_index.index("PHP_VERSION_ID < 80100") < public_index.index("app/bootstrap.php")


def test_admin_diagnostics_contract_is_present():
    app_log = read("app/AppLog.php")
    view = read("app/View.php")
    diagnostics = read("public/diagnostics.php")
    assert "[REDACTED]" in app_log
    assert "FILE_APPEND | LOCK_EX" in app_log
    assert "mailgun.domain_test" in read("app/DomainService.php")
    assert "mailgun.send_failed" in read("app/QueueService.php")
    assert "url('/diagnostics.php')" in view
    assert "require_admin()" in diagnostics
    assert "API keys, tokens, passwords, and signatures are not displayed" in diagnostics


def test_shared_host_admin_recovery_contract_is_present():
    recovery = read("app/AdminRecoveryService.php")
    page = read("public/recover-admin.php")
    assert "admin_recovery_token" in recovery
    assert "hash_equals" in recovery
    assert "bin2hex(random_bytes(24))" in recovery
    assert "resetAdminPasswordByEmail" in recovery
    assert "require_post()" in page
    assert "Never send that token to anyone" in page
