<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/email-2fa-helper.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $password = $_POST['password'] ?? '';

    if (!csrf_is_valid()) {
        $error = 'Your sign-in form expired. Refresh the page and try again.';
    } elseif (is_string($password)) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        $passwordMaterial = $user
            ? hash_hmac('sha256', $password . $user['salt'], PEPPER)
            : hash_hmac('sha256', $password . str_repeat('0', 32), PEPPER);

        if ($user && password_verify($passwordMaterial, $user['password_hash'])) {
            unset($_SESSION['dev_email_2fa_code']);
            if ((int) $user['is_2fa_enabled'] === 1) {
                try {
                    issueEmailTwoFactorCode(
                        $pdo,
                        (int) $user['id'],
                        $user['email'],
                        'verify-2fa.php'
                    );
                    session_regenerate_id(true);
                    unset($_SESSION['csrf_token']);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    unset(
                        $_SESSION['user_id'],
                        $_SESSION['username'],
                        $_SESSION['role'],
                        $_SESSION['temp_user_id'],
                        $_SESSION['temp_username'],
                        $_SESSION['temp_role'],
                        $_SESSION['totp_secret']
                    );
                    $_SESSION['temp_user_id'] = (int) $user['id'];
                    header('Location: verify-2fa.php');
                    exit;
                } catch (PDOException $e) {
                    error_log('Two-factor code issuance failed: ' . $e->getMessage());
                    $error = APP_2FA_DEMO_MODE
                        ? 'We could not generate a verification code. Please try again later or contact the site administrator.'
                        : 'We could not send a verification email. Please try again later or contact the site administrator.';
                } catch (RuntimeException $e) {
                    error_log('Two-factor code issuance failed: ' . $e->getMessage());
                    $error = $e->getMessage();
                }
            } else {
                session_regenerate_id(true);
                unset($_SESSION['csrf_token']);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                unset(
                    $_SESSION['user_id'],
                    $_SESSION['username'],
                    $_SESSION['role'],
                    $_SESSION['temp_user_id'],
                    $_SESSION['temp_username'],
                    $_SESSION['temp_role'],
                    $_SESSION['totp_secret']
                );
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                header('Location: community.php');
                exit;
            }
        } else {
            $error = 'The username or password was not recognized. Check your details and try again.';
        }
    }
}

render_header('Welcome back', 'CommunityConnect sign-in', 'Sign in to report local issues, follow community responses, and access the tools available to your role.');
?>
<div class="auth-layout">
    <section class="auth-card">
        <div class="form-heading">
            <h2>Sign in to your account</h2>
            <p>Use the username and password connected to your account.</p>
        </div>
        <?php render_notice($error, 'error'); ?>
        <form class="auth-form" method="POST" action="login.php">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <div class="field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" autocomplete="username"
                       placeholder="Your username" required autofocus>
                <small class="field-hint">This is the username you chose when creating your account.</small>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="input-with-action">
                    <input id="password" name="password" type="password" autocomplete="current-password"
                           placeholder="Your password" required>
                    <button class="input-action" type="button" data-toggle-password="password" aria-label="Show password">Show</button>
                </div>
            </div>
            <button class="btn btn-full" type="submit">Sign in securely <span aria-hidden="true">→</span></button>
        </form>
        <p class="form-bottom">New to the portal? <a href="register.php">Create an account</a></p>
    </section>

    <aside>
        <div class="auth-side">
            <div>
                <span class="side-label">Protected access</span>
                <h2>Welcome to your workspace.</h2>
                <p><?php echo APP_2FA_DEMO_MODE
                    ? 'Your community access is checked on every sign-in. In this local demo, an automatically generated code verifies accounts with two-factor authentication enabled.'
                    : 'Your community access is checked on every sign-in. If you enabled two-factor authentication, we’ll email you a one-time code after your password is accepted.'; ?></p>
                <ul class="benefit-list">
                    <li><span class="check-mark" aria-hidden="true">✓</span> Encrypted session cookies</li>
                    <li><span class="check-mark" aria-hidden="true">✓</span> Administrator and user workspaces</li>
                    <li><span class="check-mark" aria-hidden="true">✓</span> Optional two-step verification</li>
                </ul>
            </div>
            <div class="side-note">Never share your password or a one-time verification code. Support staff will never ask you to send either one.</div>
        </div>
        <div class="tip-card auth-tip">
            <span class="tip-icon" aria-hidden="true">i</span>
            <div><h3>Having trouble?</h3><p>Check that Caps Lock is off and use your account username—not your email address. <?php echo APP_2FA_DEMO_MODE
                ? 'The local 2FA demo generates and fills a code after you sign in.'
                : 'For a 2FA account, check the inbox for your account email.'; ?></p></div>
        </div>
    </aside>
</div>
<?php render_footer(); ?>
