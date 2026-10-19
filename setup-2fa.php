<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/email-2fa-helper.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT id, username, email, is_2fa_enabled FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
$stmt->closeCursor();

if (!$user) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$message = '';
$noticeType = 'info';
$enabled = (int) $user['is_2fa_enabled'] === 1;
$demoCode = '';
$requestedEmailLinkToken = $_SERVER['REQUEST_METHOD'] === 'GET'
    && is_string($_GET['token'] ?? null)
    ? $_GET['token']
    : '';
$emailLinkToken = isValidEmailTwoFactorLink($pdo, $userId, $requestedEmailLinkToken)
    ? $requestedEmailLinkToken
    : '';
if ($emailLinkToken !== '') {
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$enabled) {
    if (!csrf_is_valid()) {
        $message = 'Your form expired or could not be verified. Refresh and try again.';
        $noticeType = 'error';
    } elseif (($_POST['action'] ?? '') === 'send-code') {
        try {
            $sent = issueEmailTwoFactorCode(
                $pdo,
                $userId,
                $user['email'],
                'setup-2fa.php'
            );
            $message = $sent
                ? (APP_2FA_DEMO_MODE
                    ? 'A six-digit development demo code was generated below.'
                    : 'A six-digit verification code was sent to your account email.')
                : 'A code was sent recently. Check your email for that code before requesting another.';
            $noticeType = 'success';
        } catch (PDOException $e) {
            error_log('Two-factor enrollment code issuance failed: ' . $e->getMessage());
            $message = APP_2FA_DEMO_MODE
                ? 'We could not generate a demo code. Please try again later or contact the site administrator.'
                : 'We could not send a verification email. Please try again later or contact the site administrator.';
            $noticeType = 'error';
        } catch (RuntimeException $e) {
            error_log('Two-factor enrollment code issuance failed: ' . $e->getMessage());
            $message = $e->getMessage();
            $noticeType = 'error';
        }
    } elseif (in_array($_POST['action'] ?? '', ['verify-code', 'confirm-email-link'], true)) {
        $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
        $linkToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        unset($_SESSION['dev_email_2fa_code']);
        try {
            $challengeAccepted = ($_POST['action'] ?? '') === 'confirm-email-link'
                ? consumeEmailTwoFactorLink($pdo, $userId, $linkToken)
                : consumeEmailTwoFactorCode($pdo, $userId, $code);
            if ($challengeAccepted) {
                $update = $pdo->prepare(
                    'UPDATE users
                     SET is_2fa_enabled = 1
                     WHERE id = ? AND is_2fa_enabled = 0'
                );
                $update->execute([$userId]);
                if ($update->rowCount() === 1) {
                    $enabled = true;
                    $message = 'Email two-factor authentication is now enabled for your account.';
                    $noticeType = 'success';
                } else {
                    $message = 'Two-factor settings changed before confirmation. Refresh the page and try again.';
                    $noticeType = 'error';
                }
            } else {
                $message = 'That code was not accepted. It may be incorrect, expired, already used, or you may need to request a new code.';
                $noticeType = 'error';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Two-factor enrollment failed: ' . $e->getMessage());
            $message = 'We could not enable two-factor authentication right now. Please try again.';
            $noticeType = 'error';
        }
    }
}

if (
    APP_2FA_DEMO_MODE &&
    is_string($_SESSION['dev_email_2fa_code'] ?? null)
) {
    $demoCode = $_SESSION['dev_email_2fa_code'];
}

render_header(
    'Two-factor security',
    'Protect your account',
    'Use a one-time code emailed to your account address to add an extra verification step at sign-in.'
);
?>
<div class="content-stack">
    <section class="panel">
        <div class="panel-heading">
            <div><h2><?php echo APP_2FA_DEMO_MODE ? '' : 'Email verification setup'; ?></h2><p><?php echo APP_2FA_DEMO_MODE
                ? 'A generated code will be filled automatically for Verification.'
                : 'Codes are sent to the email address registered to your account: '; ?><?php if (!APP_2FA_DEMO_MODE): ?><strong><?php echo h($user['email']); ?></strong>.<?php endif; ?></p></div>
            <span class="role-badge <?php echo $enabled ? 'role-badge-user' : 'role-badge-admin'; ?>">
                <span class="status-dot" aria-hidden="true"></span><?php echo $enabled ? 'Enabled' : 'Not enabled'; ?>
            </span>
        </div>
        <?php if (APP_2FA_DEMO_MODE): ?>
            <div class="notice notice-error" role="alert">
                <span class="notice-icon" aria-hidden="true">!</span>
                <p> generated codes are shown and auto-submitted on this page.</p>
            </div>
        <?php endif; ?>
        <?php render_notice($message, $noticeType); ?>

        <?php if ($enabled): ?>
            <div class="tip-card">
                <span class="tip-icon" aria-hidden="true">✓</span>
                <div><h3>Your account has an extra layer of protection</h3><p><?php echo APP_2FA_DEMO_MODE
                    ? 'In this local development demo, signing in generates a temporary six-digit code and fills it automatically. This mode is not secure and must not be used for real accounts.'
                    : 'After entering your password at sign-in, a fresh six-digit code and a one-time verification link will be sent to this account email. Use the link in the same browser to continue without typing the code.'; ?></p></div>
            </div>
            <p class="section-spacer"><a class="btn btn-secondary" href="community.php">Return to CommunityConnect</a></p>
        <?php else: ?>
            <div class="security-steps">
                <div class="step-card"><span class="step-number">1</span><strong><?php echo APP_2FA_DEMO_MODE ? 'Generate a code' : 'Send a code'; ?></strong><span><?php echo APP_2FA_DEMO_MODE ? 'Generate a local testing code.' : 'We will email a one-time code to the address registered to your account.'; ?></span></div>
                <div class="step-card"><span class="step-number">2</span><strong><?php echo APP_2FA_DEMO_MODE ? 'Auto-fill the code' : 'Check your inbox'; ?></strong><span><?php echo APP_2FA_DEMO_MODE ? 'The generated code is filled in automatically.' : 'Use the six-digit code or open the verification link in this browser.'; ?></span></div>
                <div class="step-card"><span class="step-number">3</span><strong>Confirm verification</strong><span><?php echo APP_2FA_DEMO_MODE ? 'The local demo completes the enrollment automatically.' : 'Two-factor authentication turns on after email ownership is verified.'; ?></span></div>
            </div>
            <form class="reset-secret-form" method="POST" action="setup-2fa.php">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="send-code">
                <button class="btn btn-secondary" type="submit"><?php echo APP_2FA_DEMO_MODE ? 'Generate a demo verification code' : 'Email me a code and verification link'; ?></button>
                <small class="field-hint"><?php echo APP_2FA_DEMO_MODE
                    ? 'For local testing only; the code will be displayed below.'
                    : 'A new code can be sent once per minute.'; ?></small>
            </form>
            <?php if ($emailLinkToken !== ''): ?>
                <form class="auth-form section-spacer" method="POST" action="setup-2fa.php" data-auto-email-verify>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="confirm-email-link">
                    <input type="hidden" name="token" value="<?php echo h($emailLinkToken); ?>">
                    <p class="field-hint" data-email-verify-status>Confirming your email link…</p>
                    <button class="btn" type="submit">Confirm email and enable 2FA</button>
                </form>
            <?php endif; ?>
            <form class="inline-form auth-form" method="POST" action="setup-2fa.php" data-otp-autosubmit>
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="verify-code">
                <div class="field">
                    <label for="code"><?php echo APP_2FA_DEMO_MODE ? 'Six-digit demo code' : 'Six-digit email verification code'; ?></label>
                    <input class="code-input" id="code" name="code" type="text" inputmode="numeric"
                           pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code"
                           placeholder="000000" autocapitalize="off" spellcheck="false"
                           value="<?php echo h($demoCode); ?>" required>
                    <small class="field-hint"><?php echo APP_2FA_DEMO_MODE
                        ? 'The generated demo code is filled and submitted automatically. This is for local testing only.'
                        : 'The code expires in 10 minutes and can only be used once. Your browser may offer to fill it from your email.'; ?></small>
                </div>
                <button class="btn" type="submit">Verify and enable 2FA <span aria-hidden="true">→</span></button>
            </form>
        <?php endif; ?>
    </section>

    <aside class="tip-card">
        <span class="tip-icon" aria-hidden="true">!</span>
        <div><h3><?php echo APP_2FA_DEMO_MODE ? 'Demo mode warning' : 'Keep your email account secure'; ?></h3><p><?php echo APP_2FA_DEMO_MODE
            ? 'The local demo code is not a second factor: it is shown in the browser and must not be used for real accounts.'
            : 'Anyone who can access your account email may be able to receive sign-in codes. Keep that inbox protected and make sure its address is current.'; ?></p></div>
    </aside>
</div>
<?php render_footer(); ?>
