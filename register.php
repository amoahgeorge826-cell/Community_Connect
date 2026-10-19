<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$message = '';
$noticeType = 'error';
$username = '';
$email = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $password = $_POST['password'] ?? '';

    if (!csrf_is_valid()) {
        $message = 'Your form expired or could not be verified. Refresh this page and try again.';
    } elseif (!preg_match('/\A[A-Za-z0-9_.-]{3,32}\z/', $username)) {
        $message = 'Choose a username of 3–32 characters using letters, numbers, dots, underscores, or hyphens.';
    } elseif (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $message = 'Enter a valid email address.';
    } elseif (!is_string($password) || strlen($password) < 12 || strlen($password) > 4096) {
        $message = 'Use a password between 12 and 4096 characters.';
    } else {
        try {
            $salt = bin2hex(random_bytes(16));
            $passwordMaterial = hash_hmac('sha256', $password . $salt, PEPPER);
            $passwordHash = password_hash($passwordMaterial, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, salt, role)
                 SELECT ?, ?, ?, ?, CASE WHEN NOT EXISTS (SELECT 1 FROM users) THEN 'Admin' ELSE 'User' END"
            );
            $stmt->execute([$username, $email, $passwordHash, $salt]);

            $success = true;
            $noticeType = 'success';
            $message = 'Your account is ready. Sign in to open your secure workspace.';
            $username = '';
            $email = '';
        } catch (PDOException $e) {
            error_log('Registration failed: ' . $e->getMessage());
            $message = 'We could not create this account. The username or email may already be registered.';
        }
    }
}

render_header('Create your account', 'Get started', 'Set up your secure portal account in a moment. Your information is protected and your access level is assigned by the system.');
?>
<div class="auth-layout">
    <section class="auth-card">
        <div class="form-heading">
            <h2>Account details</h2>
            <p>Fields marked required must be completed.</p>
        </div>
        <?php render_notice($message, $noticeType); ?>
        <?php if ($success): ?>
            <a class="btn btn-full" href="login.php">Continue to sign in <span aria-hidden="true">→</span></a>
        <?php else: ?>
            <form class="auth-form" method="POST" action="register.php">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <div class="field">
                    <label for="username">Username <span aria-hidden="true">*</span></label>
                    <input id="username" name="username" type="text" minlength="3" maxlength="32"
                           pattern="(?:[A-Za-z0-9_.]|-)+" autocomplete="username" placeholder="e.g. alex.morgan"
                           value="<?php echo h($username); ?>" required>
                    <small class="field-hint">3–32 characters. Use letters, numbers, dots, underscores, or hyphens.</small>
                </div>
                <div class="field">
                    <label for="email">Work or personal email <span aria-hidden="true">*</span></label>
                    <input id="email" name="email" type="email" maxlength="254" autocomplete="email"
                           placeholder="you@example.com" value="<?php echo h($email); ?>" required>
                </div>
                <div class="field">
                    <label for="password">Password <span aria-hidden="true">*</span></label>
                    <div class="input-with-action">
                        <input id="password" name="password" type="password" minlength="12" maxlength="4096"
                               data-password-meter
                               autocomplete="new-password" placeholder="At least 12 characters" required>
                        <button class="input-action" type="button" data-toggle-password="password" aria-label="Show password">Show</button>
                    </div>
                    <div class="password-meter" id="password-meter" aria-hidden="true"><span></span></div>
                    <small class="password-strength-label" id="password-strength-label" aria-live="polite">Choose a unique passphrase of at least 12 characters.</small>
                </div>
                <button class="btn btn-full" type="submit">Create secure account <span aria-hidden="true">→</span></button>
            </form>
            <p class="form-bottom">Already have an account? <a href="login.php">Sign in</a></p>
        <?php endif; ?>
    </section>

    <aside>
        <div class="auth-side">
            <div>
                <span class="side-label">A safer way to work</span>
                <h2>Your account. Your secure space.</h2>
                <p>Keep your sign-in private, add a second factor, and access only the tools that match your role.</p>
                <ul class="benefit-list">
                    <li><span class="check-mark" aria-hidden="true">✓</span> Passwords are securely hashed</li>
                    <li><span class="check-mark" aria-hidden="true">✓</span> Optional email verification codes</li>
                    <li><span class="check-mark" aria-hidden="true">✓</span> Role-aware access controls</li>
                </ul>
            </div>
            <div class="side-note">For your protection, administrator privileges are never selected during public sign-up. The first account in a new installation is assigned administrator access.</div>
        </div>
        <div class="tip-card auth-tip">
            <span class="tip-icon" aria-hidden="true">✦</span>
            <div><h3>Before you continue</h3><p>Use a unique password you do not use on other sites. After signing in, open <strong>Security</strong> to enable email verification codes.</p></div>
        </div>
    </aside>
</div>
<?php render_footer(); ?>
