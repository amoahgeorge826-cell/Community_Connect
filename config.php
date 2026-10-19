<?php
require_once __DIR__ . '/includes/runtime-config.php';

define('DB_PATH', __DIR__ . '/database.sqlite');

$appEnvironment = getenv('APP_ENV') ?: 'development';
define('APP_ENVIRONMENT', $appEnvironment);
$configuredOtpDemoMode = getenv('APP_2FA_DEMO_MODE');
$otpDemoMode = $configuredOtpDemoMode === false
    ? $appEnvironment === 'development'
    : $configuredOtpDemoMode === '1';
if ($otpDemoMode && $appEnvironment !== 'development') {
    error_log('APP_2FA_DEMO_MODE can only be enabled in development.');
    http_response_code(500);
    exit('Application two-factor configuration is invalid. Ask the administrator to check the server configuration.');
}
define('APP_2FA_DEMO_MODE', $otpDemoMode);

$configuredPepper = getenv('APP_PEPPER');
if ($configuredPepper !== false && strlen($configuredPepper) < 32) {
    error_log('APP_PEPPER must be at least 32 characters long.');
    http_response_code(500);
    exit('Application configuration is invalid. Ask the administrator to check the server configuration.');
}
if ($appEnvironment === 'production' && $configuredPepper === false) {
    error_log('APP_PEPPER must be configured in production.');
    http_response_code(500);
    exit('Application configuration is invalid. Ask the administrator to check the server configuration.');
}

define('PEPPER', $configuredPepper !== false ? $configuredPepper : 'SuperSecretAppPepperKey2026!');

$configuredMailFrom = getenv('APP_MAIL_FROM');
if ($configuredMailFrom === false || $configuredMailFrom === '') {
    if ($appEnvironment === 'production') {
        error_log('APP_MAIL_FROM must be configured in production.');
        http_response_code(500);
        exit('Application email configuration is invalid. Ask the administrator to check the server configuration.');
    }
    $configuredMailFrom = 'noreply@communityconnect.local';
}
if (filter_var($configuredMailFrom, FILTER_VALIDATE_EMAIL) === false) {
    error_log('APP_MAIL_FROM must be a valid email address.');
    http_response_code(500);
    exit('Application email configuration is invalid. Ask the administrator to check the server configuration.');
}
define('APP_MAIL_FROM', $configuredMailFrom);

$configuredSmtpHost = getenv('APP_SMTP_HOST');
if ($configuredSmtpHost === false || $configuredSmtpHost === '') {
    if ($appEnvironment === 'production') {
        error_log('APP_SMTP_HOST must be configured in production.');
        http_response_code(500);
        exit('Application email configuration is invalid. Ask the administrator to check the server configuration.');
    }
    $configuredSmtpHost = '127.0.0.1';
}
$configuredSmtpPort = getenv('APP_SMTP_PORT');
$configuredSmtpPort = $configuredSmtpPort === false || $configuredSmtpPort === ''
    ? ($appEnvironment === 'production' ? 587 : 1025)
    : filter_var($configuredSmtpPort, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
if ($configuredSmtpPort === false) {
    error_log('APP_SMTP_PORT must be an integer between 1 and 65535.');
    http_response_code(500);
    exit('Application email configuration is invalid. Ask the administrator to check the server configuration.');
}
$configuredSmtpEncryption = strtolower(getenv('APP_SMTP_ENCRYPTION') ?: '');
if (
    !in_array($configuredSmtpEncryption, ['', 'tls', 'ssl'], true) ||
    ($appEnvironment === 'production' && $configuredSmtpEncryption === '')
) {
    error_log('APP_SMTP_ENCRYPTION must be tls or ssl in production, or empty for local development.');
    http_response_code(500);
    exit('Application email configuration is invalid. Ask the administrator to check the server configuration.');
}
$configuredSmtpUsername = getenv('APP_SMTP_USERNAME') ?: '';
$configuredSmtpPassword = getenv('APP_SMTP_PASSWORD') ?: '';
if (($configuredSmtpUsername === '') !== ($configuredSmtpPassword === '')) {
    error_log('APP_SMTP_USERNAME and APP_SMTP_PASSWORD must both be set or both be empty.');
    http_response_code(500);
    exit('Application email configuration is invalid. Ask the administrator to check the server configuration.');
}
define('APP_SMTP_HOST', $configuredSmtpHost);
define('APP_SMTP_PORT', (int) $configuredSmtpPort);
define('APP_SMTP_ENCRYPTION', $configuredSmtpEncryption);
define('APP_SMTP_USERNAME', $configuredSmtpUsername);
define('APP_SMTP_PASSWORD', $configuredSmtpPassword);

$configuredAppBaseUrl = getenv('APP_BASE_URL');
if ($configuredAppBaseUrl === false || $configuredAppBaseUrl === '') {
    if ($appEnvironment === 'production') {
        error_log('APP_BASE_URL must be configured in production.');
        http_response_code(500);
        exit('Application URL configuration is invalid. Ask the administrator to check the server configuration.');
    }

    $requestHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost/secure-login/api');
    $requestUrl = parse_url('http://' . $requestHost);
    $localHosts = ['localhost', '127.0.0.1', '[::1]'];
    $host = is_array($requestUrl) && in_array($requestUrl['host'] ?? '', $localHosts, true)
        ? $requestUrl['host']
        : 'localhost';
    $port = is_array($requestUrl) && isset($requestUrl['port'])
        ? ':' . (int) $requestUrl['port']
        : '';
    $scriptDirectory = trim(
        str_replace(
            '\\',
            '/',
            dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/secure-login/api/index.php'))
        ),
        '/'
    );
    $scriptDirectory = $scriptDirectory === '' ? '' : '/' . $scriptDirectory;
    $configuredAppBaseUrl = 'http://' . $host . $port . $scriptDirectory;
} else {
    $baseUrlParts = parse_url($configuredAppBaseUrl);
    if (
        !is_array($baseUrlParts) ||
        !in_array($baseUrlParts['scheme'] ?? '', ['https', 'http'], true) ||
        empty($baseUrlParts['host']) ||
        isset($baseUrlParts['user']) ||
        isset($baseUrlParts['pass']) ||
        isset($baseUrlParts['query']) ||
        isset($baseUrlParts['fragment']) ||
        ($appEnvironment === 'production' && $baseUrlParts['scheme'] !== 'https')
    ) {
        error_log('APP_BASE_URL must be an absolute HTTPS URL in production.');
        http_response_code(500);
        exit('Application URL configuration is invalid. Ask the administrator to check the server configuration.');
    }
    $configuredAppBaseUrl = rtrim($configuredAppBaseUrl, '/');
}
define('APP_BASE_URL', $configuredAppBaseUrl);

try {
    $directorySettings = current_runtime_directory_settings();
} catch (Throwable $e) {
    error_log('Runtime directory configuration could not be loaded: ' . $e->getMessage());
    http_response_code(500);
    exit('Application directory configuration is invalid. Ask the administrator to check the server configuration.');
}
define('BACKUP_DIR', $directorySettings['backup_directory']);
define('COMMUNITY_MEDIA_DIR', $directorySettings['media_directory']);

try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        salt TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ('Admin', 'User')),
        is_2fa_enabled INTEGER NOT NULL DEFAULT 0,
        email_2fa_code_hash TEXT,
        email_2fa_link_hash TEXT,
        email_2fa_expires_at INTEGER,
        email_2fa_attempts INTEGER NOT NULL DEFAULT 0,
        email_2fa_last_sent_at INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $columns = array_column(
        $pdo->query('PRAGMA table_info(users)')->fetchAll(),
        'name'
    );
    if (!in_array('is_2fa_enabled', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_2fa_enabled INTEGER NOT NULL DEFAULT 0');
    }
    $migrateFromTotp = !in_array('email_2fa_code_hash', $columns, true);
    if ($migrateFromTotp) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_2fa_code_hash TEXT');
    }
    if (!in_array('email_2fa_link_hash', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_2fa_link_hash TEXT');
    }
    if (!in_array('email_2fa_expires_at', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_2fa_expires_at INTEGER');
    }
    if (!in_array('email_2fa_attempts', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_2fa_attempts INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('email_2fa_last_sent_at', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_2fa_last_sent_at INTEGER');
    }
    if ($migrateFromTotp) {
        $legacyOtpFields = array_intersect(
            ['totp_secret', 'totp_last_counter'],
            $columns
        );
        if ($legacyOtpFields) {
            $clearLegacyOtp = implode(
                ', ',
                array_map(static fn(string $field): string => $field . ' = NULL', $legacyOtpFields)
            );
            $pdo->exec('UPDATE users SET ' . $clearLegacyOtp);
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS community_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reporter_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        reporter_name TEXT NOT NULL,
        category TEXT NOT NULL,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        location TEXT NOT NULL,
        urgency TEXT NOT NULL CHECK(urgency IN ('Low', 'Normal', 'High')),
        status TEXT NOT NULL DEFAULT 'Submitted'
            CHECK(status IN ('Submitted', 'Acknowledged', 'In Progress', 'Resolved', 'Closed')),
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $reportColumns = array_column(
        $pdo->query('PRAGMA table_info(community_reports)')->fetchAll(),
        'name'
    );
    if (!in_array('latitude', $reportColumns, true)) {
        $pdo->exec('ALTER TABLE community_reports ADD COLUMN latitude REAL');
    }
    if (!in_array('longitude', $reportColumns, true)) {
        $pdo->exec('ALTER TABLE community_reports ADD COLUMN longitude REAL');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS community_report_media (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_id INTEGER NOT NULL REFERENCES community_reports(id) ON DELETE CASCADE,
        file_name TEXT NOT NULL UNIQUE,
        media_kind TEXT NOT NULL CHECK(media_kind IN ('image', 'video', 'audio')),
        mime_type TEXT NOT NULL,
        byte_size INTEGER NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS community_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_id INTEGER NOT NULL REFERENCES community_reports(id) ON DELETE CASCADE,
        user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        author_name TEXT NOT NULL,
        update_type TEXT NOT NULL CHECK(update_type IN ('Comment', 'Status')),
        message TEXT NOT NULL,
        old_status TEXT,
        new_status TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_community_reports_status_updated ON community_reports(status, updated_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_community_updates_report_created ON community_updates(report_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_community_report_media_report ON community_report_media(report_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_community_report_media_kind ON community_report_media(media_kind, created_at DESC)');
} catch (PDOException $e) {
    error_log('Database initialization failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The application is temporarily unavailable because the database could not be initialized.');
}
