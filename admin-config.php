<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$redirect = static function (): never {
    header('Location: dashboard.php#configuration');
    exit;
};
$setFlash = static function (string $message, string $type): void {
    $_SESSION['directory_config_flash'] = ['message' => $message, 'type' => $type];
};

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Location: login.php');
    exit;
}

$roleQuery = $pdo->prepare('SELECT role FROM users WHERE id = ?');
$roleQuery->execute([(int) $_SESSION['user_id']]);
$currentRole = $roleQuery->fetchColumn();
$roleQuery->closeCursor();
if ($currentRole !== 'Admin') {
    http_response_code(403);
    $setFlash('Only an administrator can change directory settings or restore configuration points.', 'error');
    $redirect();
}
$_SESSION['role'] = 'Admin';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid()) {
    http_response_code(400);
    $setFlash('This configuration request is invalid or expired. Refresh the dashboard and try again.', 'error');
    $redirect();
}

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
try {
    if ($action === 'save_directories') {
        save_runtime_directory_settings([
            'backup_directory' => is_string($_POST['backup_directory'] ?? null) ? $_POST['backup_directory'] : '',
            'media_directory' => is_string($_POST['media_directory'] ?? null) ? $_POST['media_directory'] : '',
        ]);
        $setFlash(
            'Directory settings saved. Database records were not changed; existing media files were not moved.',
            'success'
        );
    } elseif ($action === 'create_restore_point') {
        $settings = current_runtime_directory_settings();
        $restorePointId = create_runtime_config_restore_point($settings);
        $setFlash(
            'Configuration restore point ' . $restorePointId . ' created. It contains directory settings only.',
            'success'
        );
    } elseif ($action === 'restore_configuration') {
        $restorePointId = is_string($_POST['restore_point_id'] ?? null) ? $_POST['restore_point_id'] : '';
        $settings = runtime_config_restore_point($restorePointId);
        save_runtime_directory_settings($settings);
        $setFlash(
            'Directory settings restored. Database records were not changed; existing media files were not moved.',
            'success'
        );
    } else {
        http_response_code(400);
        $setFlash('That configuration action is not recognized.', 'error');
    }
} catch (InvalidArgumentException $e) {
    $setFlash($e->getMessage(), 'error');
} catch (Throwable $e) {
    error_log('Administrator directory configuration failed: ' . $e->getMessage());
    http_response_code(500);
    $setFlash('The directory configuration could not be changed. Check server logs and directory permissions.', 'error');
}

$redirect();
