<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$currentRole = null;
if (isset($_SESSION['user_id'])) {
    $roleQuery = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $roleQuery->execute([(int) $_SESSION['user_id']]);
    $currentRole = $roleQuery->fetchColumn();
    $roleQuery->closeCursor();
    if (is_string($currentRole)) {
        $_SESSION['role'] = $currentRole;
    }
}

if (!isset($_SESSION['user_id']) || $currentRole !== 'Admin') {
    http_response_code(isset($_SESSION['user_id']) ? 403 : 401);
    render_header('Administrator access required', 'Restricted tool', 'Database backups are available only to signed-in administrator accounts.');
    ?>
    <section class="panel">
        <?php render_notice('Your current account does not have permission to create database backups.', 'warning'); ?>
        <a class="btn btn-secondary" href="<?php echo isset($_SESSION['user_id']) ? 'community.php' : 'login.php'; ?>">
            <?php echo isset($_SESSION['user_id']) ? 'Return to CommunityConnect' : 'Sign in'; ?>
        </a>
    </section>
    <?php
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid()) {
    http_response_code(400);
    render_header('Backup request not verified', 'Security check', 'For your protection, create backups from the administrator workspace.');
    ?>
    <section class="panel">
        <?php render_notice('This backup request is invalid or expired. Go back to your administrator workspace and try again.', 'warning'); ?>
        <a class="btn btn-secondary" href="community.php">Return to CommunityConnect</a>
    </section>
    <?php
    render_footer();
    exit;
}

$backupFolder = BACKUP_DIR;
if (!is_dir($backupFolder) && !mkdir($backupFolder, 0750, true) && !is_dir($backupFolder)) {
    error_log('Unable to create the database backup directory.');
    http_response_code(500);
    $message = 'The backup folder could not be created. Check the web server file permissions.';
    $success = false;
} else {
    $destinationFile = $backupFolder . DIRECTORY_SEPARATOR
        . 'backup_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.sqlite';

    try {
        $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $destinationFile) . "'");
        $success = true;
        $message = 'The database backup was created successfully in the private backup directory.';
    } catch (PDOException $e) {
        error_log('Database backup failed: ' . $e->getMessage());
        http_response_code(500);
        $message = 'The database backup could not be created. Check server logs and folder permissions.';
        $success = false;
    }
}

render_header($success ? 'Backup complete' : 'Backup could not be completed', 'Administrator tools', 'The database backup operation has finished.');
?>
<section class="panel">
    <?php render_notice($message, $success ? 'success' : 'error'); ?>
    <div class="tip-card">
        <span class="tip-icon" aria-hidden="true"><?php echo $success ? '✓' : '!'; ?></span>
        <div>
            <h3><?php echo $success ? 'Your backup is stored privately' : 'What to do next'; ?></h3>
            <p><?php echo $success ? 'The backup is kept outside the public web directory and is not directly downloadable from the portal. Move an additional copy to secure storage as part of your regular recovery plan.' : 'Ask your system administrator to check the PHP error log and confirm that the web server can write to the private backup directory.'; ?></p>
        </div>
    </div>
    <p class="section-spacer"><a class="btn btn-secondary" href="community.php">Return to CommunityConnect</a></p>
</section>
<?php render_footer(); ?>
