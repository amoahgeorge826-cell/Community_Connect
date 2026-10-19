<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/email-2fa-helper.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

if (!isset($_SESSION['temp_user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['temp_user_id'];
$verifiedAt = $_SESSION['email_2fa_verified_at'] ?? null;
if (is_int($verifiedAt) || is_float($verifiedAt)) {
    $remainingSeconds = 5 - (microtime(true) - (float) $verifiedAt);
    try {
        $stmt = $pdo->prepare(
            'SELECT id, username, role, is_2fa_enabled
             FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $verifiedUser = $stmt->fetch();
        $stmt->closeCursor();
    } catch (PDOException $e) {
        error_log('Two-factor sign-in finalization failed: ' . $e->getMessage());
        http_response_code(500);
        render_header(
            'Verification temporarily unavailable',
            'Second step',
            'We could not finish signing you in right now.'
        );
        render_notice('Please refresh this page to try again.', 'error');
        render_footer();
        exit;
    }

    if (
        !$verifiedUser ||
        !in_array($verifiedUser['role'], ['Admin', 'User'], true) ||
        (int) $verifiedUser['is_2fa_enabled'] !== 1
    ) {
        unset(
            $_SESSION['temp_user_id'],
            $_SESSION['temp_username'],
            $_SESSION['temp_role'],
            $_SESSION['email_2fa_verified_at'],
            $_SESSION['dev_email_2fa_code']
        );
        header('Location: login.php');
        exit;
    }

    if ($remainingSeconds <= 0) {
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['user_id'] = (int) $verifiedUser['id'];
        $_SESSION['username'] = $verifiedUser['username'];
        $_SESSION['role'] = $verifiedUser['role'];
        unset(
            $_SESSION['temp_user_id'],
            $_SESSION['temp_username'],
            $_SESSION['temp_role'],
            $_SESSION['totp_secret'],
            $_SESSION['email_2fa_verified_at'],
            $_SESSION['dev_email_2fa_code']
        );
        header('Location: community.php');
        exit;
    }

    render_header(
        'Verification complete',
        'Second step',
        'Your six-digit verification code was accepted.'
    );
    ?>
    <div class="auth-layout">
        <section class="auth-card">
            <div class="form-heading">
                <h2>Verification successful</h2>
                <p>You’ll be signed in automatically in
                    <span data-sign-in-countdown><?php echo (int) ceil($remainingSeconds); ?></span> seconds.</p>
            </div>
            <?php render_notice('Your verification code has been cleared.', 'success'); ?>
            <noscript><p class="field-hint">Refresh this page after five seconds to finish signing in.</p></noscript>
        </section>
    </div>
    <script>
        const redirectDelayMs = <?php echo (int) ceil($remainingSeconds * 1000); ?>;
        const redirectAt = Date.now() + redirectDelayMs;
        const countdown = document.querySelector('[data-sign-in-countdown]');
        const updateCountdown = () => {
            const secondsRemaining = Math.max(0, Math.ceil((redirectAt - Date.now()) / 1000));
            countdown.textContent = String(secondsRemaining);
            if (secondsRemaining > 0) window.setTimeout(updateCountdown, 250);
        };
        updateCountdown();
        window.setTimeout(() => window.location.replace('verify-2fa.php'), redirectDelayMs);
    </script>
    <?php
    render_footer();
    exit;
} elseif (isset($_SESSION['email_2fa_verified_at'])) {
    unset($_SESSION['email_2fa_verified_at']);
}

$error = '';
$noticeType = 'error';
$successMessage = '';
$demoCode = APP_2FA_DEMO_MODE && $_SERVER['REQUEST_METHOD'] === 'GET'
    && is_string($_SESSION['dev_email_2fa_code'] ?? null)
    ? $_SESSION['dev_email_2fa_code']
    : '';
$requestedEmailLinkToken = $_SERVER['REQUEST_METHOD'] === 'GET'
    && is_string($_GET['token'] ?? null)
    ? $_GET['token']
    : '';
$emailLinkToken = isValidEmailTwoFactorLink($pdo, $userId, $requestedEmailLinkToken)
    ? $requestedEmailLinkToken
    : '';
if ($emailLinkToken !== '') {
    header('X-Robots-Tag: noindex, nofollow');

    try {
        $stmt = $pdo->prepare(
            'SELECT id, username, role, is_2fa_enabled
             FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $stmt->closeCursor();

        if (
            $user &&
            in_array($user['role'], ['Admin', 'User'], true) &&
            (int) $user['is_2fa_enabled'] === 1 &&
            consumeEmailTwoFactorLink($pdo, $userId, $emailLinkToken)
        ) {
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            unset(
                $_SESSION['temp_user_id'],
                $_SESSION['temp_username'],
                $_SESSION['temp_role'],
                $_SESSION['totp_secret']
            );

            header('Location: community.php');
            exit;
        }

        $emailLinkToken = '';
        $error = 'That email sign-in link is expired or has already been used. Request a fresh email below.';
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Email sign-in link verification failed: ' . $e->getMessage());
        http_response_code(500);
        $emailLinkToken = '';
        $error = 'We could not verify your sign-in link right now. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = 'Your verification form expired. Refresh and sign in again.';
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        unset($_SESSION['dev_email_2fa_code']);
        $clear = $pdo->prepare(
            'UPDATE users SET email_2fa_code_hash = NULL, email_2fa_link_hash = NULL,
                              email_2fa_expires_at = NULL, email_2fa_attempts = 0
             WHERE id = ?'
        );
        $clear->execute([$userId]);
        unset(
            $_SESSION['temp_user_id'],
            $_SESSION['temp_username'],
            $_SESSION['temp_role'],
            $_SESSION['totp_secret'],
            $_SESSION['dev_email_2fa_code']
        );
        header('Location: login.php');
        exit;
    } elseif (($_POST['action'] ?? '') === 'resend-email') {
        try {
            $stmt = $pdo->prepare(
                'SELECT email FROM users WHERE id = ? AND is_2fa_enabled = 1'
            );
            $stmt->execute([$userId]);
            $email = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!is_string($email)) {
                $error = 'We could not find the email address for this account. Sign in again and retry.';
            } else {
                $sent = issueEmailTwoFactorCode(
                    $pdo,
                    $userId,
                    $email,
                    'verify-2fa.php'
                );
                $error = $sent
                    ? ''
                    : (APP_2FA_DEMO_MODE
                        ? 'A demo code could not be generated. Please try again.'
                        : 'A verification email was sent recently. Check your inbox for its code and sign-in link.');
                $noticeType = $sent ? 'success' : 'info';
                $successMessage = $sent
                    ? (APP_2FA_DEMO_MODE
                        ? 'A fresh demo code has been generated.'
                        : 'A new email with a code and automatic sign-in link is on its way.')
                    : '';
            }
        } catch (PDOException $e) {
            error_log('Two-factor email resend failed: ' . $e->getMessage());
            $error = APP_2FA_DEMO_MODE
                ? 'We could not generate a fresh demo code right now. Please try again.'
                : 'We could not resend your verification email right now. Please try again.';
        } catch (RuntimeException $e) {
            error_log('Two-factor email resend failed: ' . $e->getMessage());
            $error = $e->getMessage();
        }
    } else {
        unset($_SESSION['dev_email_2fa_code']);
        try {
            $stmt = $pdo->prepare(
                'SELECT id, username, role, is_2fa_enabled
                 FROM users WHERE id = ?'
            );
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $stmt->closeCursor();

            $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
            $linkToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
            $isEmailLinkConfirmation = ($_POST['action'] ?? '') === 'confirm-email-link';
            $accepted = $user &&
                in_array($user['role'], ['Admin', 'User'], true) &&
                (int) $user['is_2fa_enabled'] === 1 &&
                ($isEmailLinkConfirmation
                    ? consumeEmailTwoFactorLink($pdo, $userId, $linkToken)
                    : consumeEmailTwoFactorCode($pdo, $userId, $code));

            if ($accepted) {
                if (!$isEmailLinkConfirmation) {
                    $_SESSION['email_2fa_verified_at'] = microtime(true);
                    header('Location: verify-2fa.php');
                    exit;
                }

                session_regenerate_id(true);
                unset($_SESSION['csrf_token']);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                unset(
                    $_SESSION['temp_user_id'],
                    $_SESSION['temp_username'],
                    $_SESSION['temp_role'],
                    $_SESSION['totp_secret'],
                    $_SESSION['email_2fa_verified_at'],
                    $_SESSION['dev_email_2fa_code']
                );

                header('Location: community.php');
                exit;
            }

            $error = 'That code was not accepted. It may be incorrect, expired, or already used. Sign in again to request a fresh code.';
            if (APP_2FA_DEMO_MODE) {
                try {
                    $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
                    $emailStmt->execute([$userId]);
                    $email = $emailStmt->fetchColumn();
                    $emailStmt->closeCursor();

                    if (
                        !is_string($email) ||
                        !issueEmailTwoFactorCode($pdo, $userId, $email, 'verify-2fa.php')
                    ) {
                        throw new RuntimeException('A new demo code could not be generated. Please sign in again.');
                    }
                } catch (PDOException $e) {
                    error_log('Development two-factor code refresh failed: ' . $e->getMessage());
                    http_response_code(500);
                    $error = 'We could not generate a fresh verification code. Please sign in again.';
                } catch (RuntimeException $e) {
                    error_log('Development two-factor code refresh failed: ' . $e->getMessage());
                    $error = $e->getMessage();
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Two-factor verification failed: ' . $e->getMessage());
            http_response_code(500);
            $error = 'We could not verify your sign-in right now. Please try again.';
        }
    }
}

if (APP_2FA_DEMO_MODE && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $currentDemoCode = is_string($_SESSION['dev_email_2fa_code'] ?? null)
        ? $_SESSION['dev_email_2fa_code']
        : '';

    try {
        if (!isValidEmailTwoFactorCode($pdo, $userId, $currentDemoCode)) {
            $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $emailStmt->execute([$userId]);
            $email = $emailStmt->fetchColumn();
            $emailStmt->closeCursor();

            if (
                !is_string($email) ||
                !issueEmailTwoFactorCode($pdo, $userId, $email, 'verify-2fa.php')
            ) {
                throw new RuntimeException('A fresh demo code could not be generated. Please sign in again.');
            }
        }
    } catch (PDOException $e) {
        error_log('Development two-factor code generation failed: ' . $e->getMessage());
        http_response_code(500);
        unset($_SESSION['dev_email_2fa_code']);
        $demoCode = '';
        $error = 'We could not generate a verification code. Please sign in again.';
    } catch (RuntimeException $e) {
        error_log('Development two-factor code generation failed: ' . $e->getMessage());
        unset($_SESSION['dev_email_2fa_code']);
        $demoCode = '';
        $error = $e->getMessage();
    }
}

if (
    APP_2FA_DEMO_MODE &&
    is_string($_SESSION['dev_email_2fa_code'] ?? null)
) {
    $demoCode = $_SESSION['dev_email_2fa_code'];
}

$stmt = $pdo->prepare(
    'SELECT username, role, is_2fa_enabled FROM users WHERE id = ?'
);
$stmt->execute([$userId]);
$pendingUser = $stmt->fetch();
$stmt->closeCursor();

if (
    !$pendingUser ||
    !in_array($pendingUser['role'], ['Admin', 'User'], true) ||
    (int) $pendingUser['is_2fa_enabled'] !== 1
) {
    unset(
        $_SESSION['temp_user_id'],
        $_SESSION['temp_username'],
        $_SESSION['temp_role'],
        $_SESSION['totp_secret']
    );
    header('Location: login.php');
    exit;
}

$isPendingAdmin = $pendingUser['role'] === 'Admin';
render_header(
    'Verify your identity',
    APP_2FA_DEMO_MODE ? 'Development demo' : 'Second step',
    APP_2FA_DEMO_MODE
        ? 'Development-only demo: a generated code is shown in this page and is not secure two-factor authentication.'
        : 'Enter the one-time code sent to the email address registered to your account.'
);
?>
<div class="auth-layout">
    <section class="auth-card">
        <div class="form-heading">
            <h2><?php echo APP_2FA_DEMO_MODE ? 'Verifying your sign-in' : 'Check your email to continue'; ?></h2>
            <p>Your password was accepted for <strong><?php echo h($pendingUser['username']); ?></strong>.
                <?php if (APP_2FA_DEMO_MODE): ?>
                    This local demo generates and fills the code automatically. It is visible here for testing only.
                <?php else: ?>
                    Enter the six-digit code from your verification email.
                <?php endif; ?>
            </p>
        </div>
        <?php if (APP_2FA_DEMO_MODE): ?>
            <div class="notice notice-error" role="alert">
                <span class="notice-icon" aria-hidden="true">!</span>
                <p>Development demo only: the code is displayed on this page. Do not use this mode for real accounts or production.</p>
            </div>
        <?php endif; ?>
        <div class="pending-role-card">
            <span class="pending-role-icon" aria-hidden="true"><?php echo $isPendingAdmin ? '◆' : '●'; ?></span>
            <span><small>ACCOUNT ROLE</small><strong><?php echo $isPendingAdmin ? 'Administrator' : 'Standard user'; ?></strong></span>
            <span class="role-badge <?php echo $isPendingAdmin ? 'role-badge-admin' : 'role-badge-user'; ?>">
                <?php echo $isPendingAdmin ? 'Response team access' : 'Community access'; ?>
            </span>
        </div>
        <p class="field-hint role-verification-note">This role is read from your account record. <?php echo APP_2FA_DEMO_MODE
            ? 'The local demo code verifies this sign-in; it does not grant or change a role.'
            : 'The email code verifies your account; it does not grant or change a role.'; ?></p>
        <?php render_notice($error, $error !== '' && isset($noticeType) ? $noticeType : 'error'); ?>
        <?php render_notice($successMessage, 'success'); ?>
        <?php if (!APP_2FA_DEMO_MODE): ?>
        <div class="tip-card">
            <span class="tip-icon" aria-hidden="true">✉</span>
            <div>
                <h3>Want to continue without entering a code?</h3>
                <p>Open the automatic sign-in link in your latest verification email in this same browser. The link will complete verification for you.</p>
                <form method="POST" action="verify-2fa.php">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="resend-email">
                    <button class="text-button" type="submit">Send me a fresh code and sign-in link</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <form class="auth-form" method="POST" action="verify-2fa.php" data-otp-autosubmit>
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <div class="field">
                <label for="code"><?php echo APP_2FA_DEMO_MODE ? 'Generated demo code' : 'Email verification code'; ?></label>
                <input class="code-input" id="code" name="code" type="text" inputmode="numeric"
                       pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code"
                       placeholder="000000" autocapitalize="off" spellcheck="false"
                       value="<?php echo h($demoCode); ?>" autofocus required>
                <small class="field-hint"><?php echo APP_2FA_DEMO_MODE
                    ? 'A fresh six-digit demo code is generated and filled for this local sign-in.'
                    : 'The code expires in 10 minutes and can only be used once.'; ?></small>
            </div>
            <button class="btn btn-full" type="submit">Verify and continue to CommunityConnect <span aria-hidden="true">→</span></button>
        </form>
        <form class="cancel-auth-form" method="POST" action="verify-2fa.php">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="cancel">
            <button class="text-button" type="submit">Cancel and return to sign in</button>
        </form>
    </section>
    <aside>
        <div class="auth-side">
            <div>
                <span class="side-label">Role-aware verification</span>
                <h2>One more step to your workspace.</h2>
                <p>A valid code confirms access to the email address connected to your account. The system then loads your current role and opens the matching workspace.</p>
                <ul class="benefit-list">
                    <li><span class="check-mark" aria-hidden="true">✓</span> Role is checked against your account</li>
                    <li><span class="check-mark" aria-hidden="true">✓</span> Admin access requires a valid OTP</li>
                    <li><span class="check-mark" aria-hidden="true">✓</span> Each code can only be used once</li>
                </ul>
            </div>
            <div class="side-note">Your OTP proves control of the email account registered to your profile. Administrator permissions are determined by the account role stored on the server—not by the OTP itself.</div>
        </div>
        <div class="tip-card auth-tip">
            <span class="tip-icon" aria-hidden="true">i</span>
            <div><h3><?php echo APP_2FA_DEMO_MODE ? 'Demo code issue?' : 'Code not received?'; ?></h3><p><?php echo APP_2FA_DEMO_MODE
                ? 'Reload this page to generate a fresh code automatically, or sign in again if the local demo still cannot verify.'
                : 'Check your spam folder and confirm that your account email is up to date. You can sign in again to request a fresh code once the resend cooldown has passed.'; ?></p></div>
        </div>
    </aside>
</div>
<?php render_footer(); ?>
