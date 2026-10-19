<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_login();

$roleQuery = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ?');
$roleQuery->execute([(int) $_SESSION['user_id']]);
$admin = $roleQuery->fetch();
if (!$admin || $admin['role'] !== 'Admin') {
    http_response_code(403);
    render_header('Administrator access required', 'Restricted area', 'User administration is available only to administrator accounts.');
    ?>
    <section class="panel">
        <?php render_notice('Your account does not have permission to manage users.', 'warning'); ?>
        <a class="btn btn-secondary" href="community.php">Return to CommunityConnect</a>
    </section>
    <?php
    render_footer();
    exit;
}
$_SESSION['role'] = 'Admin';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = 'Your request expired or could not be verified. Refresh the page and try again.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        try {
            if ($action === 'create') {
                $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
                $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
                $password = $_POST['password'] ?? '';
                $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
                if (!preg_match('/\A[A-Za-z0-9_.-]{3,32}\z/', $username)) {
                    $error = 'Choose a username of 3–32 letters, numbers, dots, underscores, or hyphens.';
                } elseif (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $error = 'Enter a valid email address.';
                } elseif (!is_string($password) || strlen($password) < 12 || strlen($password) > 4096) {
                    $error = 'Use an initial password between 12 and 4,096 characters.';
                } elseif (!in_array($role, ['Admin', 'User'], true)) {
                    $error = 'Choose a valid account role.';
                } else {
                    $salt = bin2hex(random_bytes(16));
                    $material = hash_hmac('sha256', $password . $salt, PEPPER);
                    $insert = $pdo->prepare('INSERT INTO users (username, email, password_hash, salt, role) VALUES (?, ?, ?, ?, ?)');
                    $insert->execute([$username, $email, password_hash($material, PASSWORD_DEFAULT), $salt, $role]);
                    $_SESSION['admin_users_flash'] = 'The account was created.';
                    header('Location: admin-users.php');
                    exit;
                }
            } elseif ($action === 'update') {
                $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
                $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
                $password = $_POST['password'] ?? '';
                $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
                if ($id === false || $id === null) {
                    $error = 'Choose a valid account.';
                } elseif (!preg_match('/\A[A-Za-z0-9_.-]{3,32}\z/', $username)) {
                    $error = 'Choose a username of 3–32 letters, numbers, dots, underscores, or hyphens.';
                } elseif (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $error = 'Enter a valid email address.';
                } elseif (!in_array($role, ['Admin', 'User'], true)) {
                    $error = 'Choose a valid account role.';
                } elseif ($password !== '' && (!is_string($password) || strlen($password) < 12 || strlen($password) > 4096)) {
                    $error = 'A replacement password must be between 12 and 4,096 characters.';
                } elseif ((int) $id === (int) $admin['id'] && $role !== 'Admin') {
                    $error = 'You cannot remove administrator access from your own account.';
                } else {
                    $targetQuery = $pdo->prepare('SELECT role FROM users WHERE id = ?');
                    $targetQuery->execute([$id]);
                    $targetRole = $targetQuery->fetchColumn();
                    if (!is_string($targetRole)) {
                        $error = 'That account is no longer available.';
                    } elseif ($targetRole === 'Admin' && $role !== 'Admin') {
                        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn();
                        if ($adminCount <= 1) {
                            $error = 'At least one administrator account must remain.';
                        }
                    }

                    if ($error === '') {
                        if ($password === '') {
                            $update = $pdo->prepare('UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?');
                            $update->execute([$username, $email, $role, $id]);
                        } else {
                            $salt = bin2hex(random_bytes(16));
                            $material = hash_hmac('sha256', $password . $salt, PEPPER);
                            $update = $pdo->prepare('UPDATE users SET username = ?, email = ?, role = ?, salt = ?, password_hash = ? WHERE id = ?');
                            $update->execute([$username, $email, $role, $salt, password_hash($material, PASSWORD_DEFAULT), $id]);
                        }
                        $_SESSION['admin_users_flash'] = 'The account was updated.';
                        header('Location: admin-users.php');
                        exit;
                    }
                }
            } elseif ($action === 'delete') {
                $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                if ($id === false || $id === null) {
                    $error = 'Choose a valid account.';
                } elseif ((int) $id === (int) $admin['id']) {
                    $error = 'You cannot delete the account you are currently using.';
                } else {
                    $pdo->beginTransaction();
                    $targetQuery = $pdo->prepare('SELECT role FROM users WHERE id = ?');
                    $targetQuery->execute([$id]);
                    $targetRole = $targetQuery->fetchColumn();
                    if (!is_string($targetRole)) {
                        $pdo->rollBack();
                        $error = 'That account is no longer available.';
                    } elseif ($targetRole === 'Admin' && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn() <= 1) {
                        $pdo->rollBack();
                        $error = 'At least one administrator account must remain.';
                    } else {
                        $delete = $pdo->prepare('DELETE FROM users WHERE id = ?');
                        $delete->execute([$id]);
                        $pdo->commit();
                        $_SESSION['admin_users_flash'] = 'The account was deleted. Its community reports remain available.';
                        header('Location: admin-users.php');
                        exit;
                    }
                }
            } else {
                http_response_code(400);
                $error = 'That account action is not recognized.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Administrator user management failed: ' . $e->getMessage());
            $error = 'The account could not be saved. The username or email may already be in use.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Administrator user management failed: ' . $e->getMessage());
            $error = 'The account action could not be completed. Please try again.';
        }
    }
}

$flash = is_string($_SESSION['admin_users_flash'] ?? null) ? $_SESSION['admin_users_flash'] : '';
unset($_SESSION['admin_users_flash']);
$users = $pdo->query('SELECT id, username, email, role, is_2fa_enabled, created_at FROM users ORDER BY id DESC LIMIT 200')->fetchAll();
render_header('Manage community accounts', 'Administrator tools', 'Create, update, and remove accounts. Protect administrator access and keep at least one administrator active.');
?>
<div class="content-stack">
    <?php render_notice($flash, 'success'); ?>
    <?php render_notice($error, 'error'); ?>
    <section class="panel">
        <div class="panel-heading"><div><span class="metric-label">NEW MEMBER</span><h2>Create an account</h2><p>Set an initial password and assign the account role.</p></div></div>
        <form class="community-form" method="POST" action="admin-users.php">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="create">
            <div class="community-form-grid">
                <div class="field"><label for="new-username">Username</label><input id="new-username" name="username" minlength="3" maxlength="32" pattern="(?:[A-Za-z0-9_.]|-)+" required></div>
                <div class="field"><label for="new-email">Email</label><input id="new-email" name="email" type="email" maxlength="254" required></div>
                <div class="field"><label for="new-password">Initial password</label><input id="new-password" name="password" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required></div>
                <div class="field"><label for="new-role">Role</label><select id="new-role" name="role"><option value="User">Community member</option><option value="Admin">Administrator</option></select></div>
            </div>
            <button class="btn" type="submit">Create account</button>
        </form>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><span class="metric-label">ACCESS CONTROL</span><h2>Community accounts</h2><p>Edit a member's profile or role. Leave the password blank to keep it unchanged.</p></div><span class="metric-label"><?php echo count($users); ?> shown (up to 200)</span></div>
        <?php if (!$users): ?>
            <div class="empty-state">There are no accounts to manage.</div>
        <?php else: ?>
            <div class="managed-users">
                <?php foreach ($users as $managedUser): ?>
                    <article class="managed-user">
                        <form class="community-form" method="POST" action="admin-users.php">
                            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?php echo (int) $managedUser['id']; ?>">
                            <div class="managed-user-heading"><div><h3><?php echo h($managedUser['username']); ?></h3><span class="account-security"><?php echo (int) $managedUser['is_2fa_enabled'] === 1 ? 'Two-factor authentication enabled' : 'Two-factor authentication not enabled'; ?> · Joined <?php echo h(date('M j, Y', strtotime($managedUser['created_at']))); ?></span></div><span class="role-tag <?php echo $managedUser['role'] === 'Admin' ? 'role-tag-admin' : ''; ?>"><?php echo $managedUser['role'] === 'Admin' ? 'Administrator' : 'Member'; ?></span></div>
                            <div class="community-form-grid">
                                <div class="field"><label for="username-<?php echo (int) $managedUser['id']; ?>">Username</label><input id="username-<?php echo (int) $managedUser['id']; ?>" name="username" minlength="3" maxlength="32" pattern="(?:[A-Za-z0-9_.]|-)+" value="<?php echo h($managedUser['username']); ?>" required></div>
                                <div class="field"><label for="email-<?php echo (int) $managedUser['id']; ?>">Email</label><input id="email-<?php echo (int) $managedUser['id']; ?>" name="email" type="email" maxlength="254" value="<?php echo h($managedUser['email']); ?>" required></div>
                                <div class="field"><label for="password-<?php echo (int) $managedUser['id']; ?>">Set new password <span class="field-hint">(optional; minimum 12 characters)</span></label><input id="password-<?php echo (int) $managedUser['id']; ?>" name="password" type="password" minlength="12" maxlength="4096" autocomplete="new-password"></div>
                                <div class="field"><label for="role-<?php echo (int) $managedUser['id']; ?>">Role</label><select id="role-<?php echo (int) $managedUser['id']; ?>" name="role" <?php echo (int) $managedUser['id'] === (int) $admin['id'] ? 'disabled' : ''; ?>><option value="User" <?php echo $managedUser['role'] === 'User' ? 'selected' : ''; ?>>Community member</option><option value="Admin" <?php echo $managedUser['role'] === 'Admin' ? 'selected' : ''; ?>>Administrator</option></select><?php if ((int) $managedUser['id'] === (int) $admin['id']): ?><input type="hidden" name="role" value="Admin"><?php endif; ?></div>
                            </div>
                            <div class="managed-user-actions"><button class="btn btn-secondary" type="submit">Save account</button></div>
                        </form>
                        <?php if ((int) $managedUser['id'] !== (int) $admin['id']): ?>
                            <form class="delete-user-form" method="POST" action="admin-users.php" data-confirm="Delete this account? Their community reports will remain visible.">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?php echo (int) $managedUser['id']; ?>">
                                <button class="btn btn-danger" type="submit">Delete account</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php render_footer(); ?>
