<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_login();

$stmt = $pdo->prepare('SELECT id, username, email, role, is_2fa_enabled, created_at FROM users WHERE id = ?');
$stmt->execute([(int) $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || !in_array($user['role'], ['Admin', 'User'], true)) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];
$isAdmin = $user['role'] === 'Admin';
$totalAccounts = 0;
$adminCount = 0;
$protectedAccounts = 0;
$accountList = [];
$reportStats = ['reports' => 0, 'updates' => 0, 'attachments' => 0];
$reportList = [];
$updateList = [];
$directorySettings = [];
$restorePoints = [];
$databaseBackups = [];
$directoryConfigFlash = $_SESSION['directory_config_flash'] ?? null;
unset($_SESSION['directory_config_flash']);
if ($isAdmin) {
    $stats = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN role = 'Admin' THEN 1 ELSE 0 END) AS admins,
                SUM(CASE WHEN is_2fa_enabled = 1 THEN 1 ELSE 0 END) AS protected
         FROM users"
    )->fetch();
    $totalAccounts = (int) $stats['total'];
    $adminCount = (int) $stats['admins'];
    $protectedAccounts = (int) $stats['protected'];
    $accountList = $pdo->query(
        'SELECT username, email, role, is_2fa_enabled, created_at FROM users ORDER BY id DESC LIMIT 100'
    )->fetchAll();
    $reportStats = $pdo->query(
        'SELECT
            (SELECT COUNT(*) FROM community_reports) AS reports,
            (SELECT COUNT(*) FROM community_updates) AS updates,
            (SELECT COUNT(*) FROM community_report_media) AS attachments'
    )->fetch();
    $reportList = $pdo->query(
        'SELECT id, reporter_name, category, title, location, urgency, status, created_at
         FROM community_reports ORDER BY created_at DESC, id DESC LIMIT 100'
    )->fetchAll();
    $updateList = $pdo->query(
        'SELECT reports.title, updates.author_name, updates.update_type, updates.message,
                updates.new_status, updates.created_at
         FROM community_updates AS updates
         JOIN community_reports AS reports ON reports.id = updates.report_id
         ORDER BY updates.created_at DESC, updates.id DESC LIMIT 50'
    )->fetchAll();
    $directorySettings = current_runtime_directory_settings();
    $restorePoints = list_runtime_config_restore_points();
    if (is_dir(BACKUP_DIR)) {
        $backupFiles = glob(BACKUP_DIR . DIRECTORY_SEPARATOR . 'backup_*.sqlite');
        if ($backupFiles === false) {
            throw new RuntimeException('Database backups could not be listed.');
        }
        rsort($backupFiles, SORT_STRING);
        foreach (array_slice($backupFiles, 0, 50) as $backupFile) {
            $backupSize = filesize($backupFile);
            $backupModified = filemtime($backupFile);
            if ($backupSize === false || $backupModified === false) {
                error_log('A database backup could not be inspected: ' . basename($backupFile));
                continue;
            }
            $databaseBackups[] = [
                'name' => basename($backupFile),
                'size' => $backupSize,
                'modified' => $backupModified,
            ];
        }
    }
}

render_header(
    $isAdmin ? 'Administration overview' : 'Member home',
    $isAdmin ? 'Administrator workspace' : 'Your personal workspace',
    $isAdmin
        ? 'Monitor portal access, review account roles, and run administrator tools from one place.'
        : 'Manage your personal sign-in security and see the account details associated with your membership.'
);
?>
<div class="content-stack dashboard-<?php echo $isAdmin ? 'admin' : 'user'; ?>">
    <?php if (is_array($directoryConfigFlash) && is_string($directoryConfigFlash['message'] ?? null)): ?>
        <?php render_notice(
            $directoryConfigFlash['message'],
            in_array($directoryConfigFlash['type'] ?? '', ['success', 'error', 'info'], true)
                ? $directoryConfigFlash['type']
                : 'info'
        ); ?>
    <?php endif; ?>
    <section class="panel role-hero">
        <div class="panel-heading">
            <div class="dashboard-welcome">
                <div class="avatar" aria-hidden="true"><?php echo h(mb_strtoupper(mb_substr($user['username'], 0, 1, 'UTF-8'), 'UTF-8')); ?></div>
                <div class="welcome-copy">
                    <span class="metric-label"><?php echo $isAdmin ? 'PORTAL ADMINISTRATOR' : 'MEMBER ACCOUNT'; ?></span>
                    <h2><?php echo $isAdmin ? 'Good to see you' : 'Welcome back'; ?>, <?php echo h($user['username']); ?></h2>
                    <p><?php echo $isAdmin ? 'You have administrator access to portal management tools.' : 'You are signed in to your personal member workspace.'; ?></p>
                </div>
            </div>
            <span class="role-badge <?php echo $isAdmin ? 'role-badge-admin' : 'role-badge-user'; ?>">
                <span class="status-dot" aria-hidden="true"></span>
                <?php echo $isAdmin ? 'Administrator' : 'Standard user'; ?>
            </span>
        </div>

        <div class="metric-grid">
            <article class="metric-card">
                <span class="metric-label"><?php echo $isAdmin ? 'PORTAL ACCOUNTS' : 'YOUR SECURITY'; ?></span>
                <div class="metric-value">
                    <?php if ($isAdmin): ?>
                        <?php echo h((string) $totalAccounts); ?> registered
                    <?php else: ?>
                        <span class="status-dot <?php echo (int) $user['is_2fa_enabled'] === 1 ? 'dot-enabled' : 'dot-disabled'; ?>"></span>
                        <?php echo (int) $user['is_2fa_enabled'] === 1 ? '2FA enabled' : '2FA not enabled'; ?>
                    <?php endif; ?>
                </div>
                <p class="metric-caption"><?php echo $isAdmin ? h((string) $adminCount) . ' administrator account(s)' : 'Add a second sign-in step for more protection.'; ?></p>
            </article>
            <article class="metric-card">
                <span class="metric-label"><?php echo $isAdmin ? 'ACCOUNTS WITH 2FA' : 'MEMBER SINCE'; ?></span>
                <div class="metric-value">
                    <?php echo $isAdmin
                        ? h((string) $protectedAccounts) . ' of ' . h((string) $totalAccounts)
                        : h(date('M Y', strtotime($user['created_at']))); ?>
                </div>
                <p class="metric-caption"><?php echo $isAdmin ? 'Members with email verification enabled.' : 'Your membership is active in the portal.'; ?></p>
            </article>
            <article class="metric-card">
                <span class="metric-label"><?php echo $isAdmin ? 'YOUR SECURITY' : 'ACCESS LEVEL'; ?></span>
                <?php if ($isAdmin): ?>
                    <div class="metric-value <?php echo (int) $user['is_2fa_enabled'] === 1 ? 'security-state-enabled' : 'security-state-disabled'; ?>">
                        <span class="status-dot"></span><?php echo (int) $user['is_2fa_enabled'] === 1 ? 'Protected' : 'Not enabled'; ?>
                    </div>
                    <p class="metric-caption"><?php echo (int) $user['is_2fa_enabled'] === 1 ? 'Your administrator sign-in has 2FA.' : 'Protect your administrator sign-in.'; ?></p>
                <?php else: ?>
                    <div class="metric-value">Standard user</div>
                    <p class="metric-caption">Member permissions are active</p>
                <?php endif; ?>
            </article>
        </div>
    </section>

    <section class="panel security-panel">
        <div class="panel-heading">
            <div>
                <span class="metric-label"><?php echo $isAdmin ? 'PRIVILEGED ACCOUNT' : 'PERSONAL SETTINGS'; ?></span>
                <h2><?php echo $isAdmin ? 'Secure your admin access' : 'Your account security'; ?></h2>
                <p><?php echo $isAdmin
                    ? 'Administrator accounts can manage portal data. Keep yours protected with email verification.'
                    : 'Only you can manage your personal two-factor sign-in setting.'; ?></p>
            </div>
            <a class="btn btn-secondary" href="setup-2fa.php"><?php echo (int) $user['is_2fa_enabled'] === 1 ? 'View security status' : 'Set up two-factor'; ?> <span aria-hidden="true">→</span></a>
        </div>
        <div class="tip-card">
            <span class="tip-icon" aria-hidden="true">✦</span>
            <div><h3>Why enable two-factor authentication?</h3><p>A second, short-lived code emailed to your account helps protect your account if someone learns your password. Keep your email inbox secure and its address up to date.</p></div>
        </div>
    </section>

    <?php if ($isAdmin): ?>
        <section class="panel admin-tools-panel" id="admin-tools">
            <div class="panel-heading">
                <div>
                    <span class="metric-label">ADMINISTRATION</span>
                    <h2>Portal management</h2>
                    <p>These controls are available only to administrator accounts.</p>
                </div>
                <span class="role-badge role-badge-admin"><span class="status-dot" aria-hidden="true"></span>Admin only</span>
            </div>
            <div class="admin-callout">
                <div class="admin-callout-head"><span class="admin-icon" aria-hidden="true">◆</span><h3>Database backup</h3></div>
                <p>Create a consistent SQLite backup. The backup is stored outside the public web directory.</p>
                <div class="admin-actions">
                    <form method="POST" action="backup.php">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <button class="btn" type="submit">Create database backup <span aria-hidden="true">→</span></button>
                    </form>
                    <a href="GUIDE.md">Read administrator guide</a>
                </div>
            </div>
        </section>

        <section class="panel" id="configuration">
            <div class="panel-heading">
                <div><span class="metric-label">CONFIGURATION RESTORE POINTS</span><h2>Backup and storage settings</h2><p>Back up database records separately, and save restore points for the backup and media directory settings.</p></div>
            </div>
            <form class="auth-form" method="POST" action="admin-config.php">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="save_directories">
                <div class="field">
                    <label for="backup-directory">Database backup directory</label>
                    <input id="backup-directory" name="backup_directory" type="text" value="<?php echo h($directorySettings['backup_directory']); ?>" required>
                </div>
                <div class="field">
                    <label for="media-directory">Community media directory</label>
                    <input id="media-directory" name="media_directory" type="text" value="<?php echo h($directorySettings['media_directory']); ?>" required>
                </div>
                <div class="admin-actions">
                    <button class="btn" type="submit">Save directory settings</button>
                </div>
            </form>
            <p class="field-hint">Use absolute paths outside the public web directory. Saving or restoring these settings does not change database records or move existing media files. A restored media path must point to the folder containing the existing files for them to remain available.</p>
            <form method="POST" action="admin-config.php" class="section-spacer">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="create_restore_point">
                <button class="btn btn-secondary" type="submit">Create configuration restore point</button>
            </form>
            <?php if ($restorePoints): ?>
                <h3 class="section-spacer">Saved configuration restore points</h3>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Created</th><th>Backup directory</th><th>Media directory</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($restorePoints as $restorePoint): ?>
                            <tr>
                                <td><?php echo h(date('M j, Y g:i a', strtotime($restorePoint['created_at']))); ?></td>
                                <td><?php echo h($restorePoint['backup_directory']); ?></td>
                                <td><?php echo h($restorePoint['media_directory']); ?></td>
                                <td>
                                    <form method="POST" action="admin-config.php" data-confirm="Restore these directory settings? Database records and media files will not be changed.">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="restore_configuration">
                                        <input type="hidden" name="restore_point_id" value="<?php echo h($restorePoint['id']); ?>">
                                        <button class="btn btn-secondary" type="submit">Restore settings</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="field-hint">Showing up to the 50 most recent restore points.</p>
            <?php else: ?>
                <div class="empty-state">No configuration restore points have been created.</div>
            <?php endif; ?>
            <h3 class="section-spacer">Database backup files</h3>
            <?php if ($databaseBackups): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Backup file</th><th>Size</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php foreach ($databaseBackups as $databaseBackup): ?>
                            <tr>
                                <td><?php echo h($databaseBackup['name']); ?></td>
                                <td><?php echo h(number_format($databaseBackup['size'] / 1048576, 2)); ?> MB</td>
                                <td><?php echo h(date('M j, Y g:i a', $databaseBackup['modified'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($databaseBackups) === 50): ?>
                    <p class="field-hint">Showing the 50 most recent database backups.</p>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">No database backup files are present in the configured backup directory.</div>
            <?php endif; ?>
        </section>

        <section class="panel" id="account-directory">
            <div class="panel-heading">
                <div><span class="metric-label">MEMBERS</span><h2>Account directory</h2><p>Review account roles, two-factor status, and membership dates.</p></div>
                <span class="metric-label"><?php echo h((string) $totalAccounts); ?> total</span>
            </div>
            <?php if ($accountList): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Username</th><th>Email</th><th>Role</th><th>2FA</th><th>Joined</th></tr></thead>
                        <tbody>
                        <?php foreach ($accountList as $account): ?>
                            <tr>
                                <td><?php echo h($account['username']); ?></td>
                                <td><?php echo h($account['email']); ?></td>
                                <td><span class="role-tag <?php echo $account['role'] === 'Admin' ? 'role-tag-admin' : ''; ?>"><?php echo h($account['role']); ?></span></td>
                                <td><?php echo (int) $account['is_2fa_enabled'] === 1 ? 'Enabled' : 'Not enabled'; ?></td>
                                <td><?php echo h(date('M j, Y', strtotime($account['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalAccounts > count($accountList)): ?>
                    <p class="field-hint">Showing the most recent 100 accounts.</p>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">No accounts are available yet.</div>
            <?php endif; ?>
        </section>

        <section class="panel" id="database-overview">
            <div class="panel-heading">
                <div><span class="metric-label">DATABASE OVERVIEW</span><h2>Community records</h2><p>Recent report and update records stored by the portal.</p></div>
                <span class="metric-label"><?php echo h((string) $reportStats['reports']); ?> reports · <?php echo h((string) $reportStats['updates']); ?> updates · <?php echo h((string) $reportStats['attachments']); ?> attachments</span>
            </div>
            <div class="tip-card section-spacer">
                <span class="tip-icon" aria-hidden="true">i</span>
                <div><h3>Authentication secrets are protected</h3><p>Password hashes, salts, one-time verification tokens, and the application pepper are never displayed here. The pepper is stored in server configuration, not in the database.</p></div>
            </div>
            <?php if ($reportList): ?>
                <h3>Latest reports</h3>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Status</th><th>Urgency</th><th>Location</th><th>Reported by</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php foreach ($reportList as $report): ?>
                            <tr>
                                <td><?php echo (int) $report['id']; ?></td>
                                <td><?php echo h($report['title']); ?></td>
                                <td><?php echo h($report['category']); ?></td>
                                <td><?php echo h($report['status']); ?></td>
                                <td><?php echo h($report['urgency']); ?></td>
                                <td><?php echo h($report['location']); ?></td>
                                <td><?php echo h($report['reporter_name']); ?></td>
                                <td><?php echo h(date('M j, Y', strtotime($report['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ((int) $reportStats['reports'] > count($reportList)): ?>
                    <p class="field-hint">Showing the most recent 100 reports.</p>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">No community reports are available yet.</div>
            <?php endif; ?>
            <?php if ($updateList): ?>
                <h3 class="section-spacer">Latest updates</h3>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Report</th><th>Author</th><th>Type</th><th>Message</th><th>Status</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php foreach ($updateList as $update): ?>
                            <tr>
                                <td><?php echo h($update['title']); ?></td>
                                <td><?php echo h($update['author_name']); ?></td>
                                <td><?php echo h($update['update_type']); ?></td>
                                <td><?php echo h(mb_strimwidth($update['message'], 0, 140, '…', 'UTF-8')); ?></td>
                                <td><?php echo h((string) ($update['new_status'] ?? '')); ?></td>
                                <td><?php echo h(date('M j, Y', strtotime($update['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ((int) $reportStats['updates'] > count($updateList)): ?>
                    <p class="field-hint">Showing the most recent 50 updates.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="panel" id="my-profile">
            <div class="panel-heading">
                <div><span class="metric-label">YOUR PROFILE</span><h2>Account details</h2><p>Your personal profile information. Contact an administrator if these details need to change.</p></div>
                <span class="role-badge role-badge-user"><span class="status-dot" aria-hidden="true"></span>Member</span>
            </div>
            <dl class="profile-grid">
                <div><dt>Username</dt><dd><?php echo h($user['username']); ?></dd></div>
                <div><dt>Email address</dt><dd><?php echo h($user['email']); ?></dd></div>
                <div><dt>Account role</dt><dd><span class="role-tag">Standard user</span></dd></div>
                <div><dt>Member since</dt><dd><?php echo h(date('F j, Y', strtotime($user['created_at']))); ?></dd></div>
            </dl>
            <div class="tip-card section-spacer">
                <span class="tip-icon" aria-hidden="true">i</span>
                <div><h3>About your access</h3><p>Your member workspace includes your profile and security settings. Account directory and database management tools are restricted to administrators.</p></div>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
