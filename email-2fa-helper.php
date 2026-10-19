<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/vendor/autoload.php';

function issueEmailTwoFactorCode(PDO $pdo, int $userId, string $email, string $verificationPage): bool
{
    if (
        filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
        !in_array($verificationPage, ['setup-2fa.php', 'verify-2fa.php'], true)
    ) {
        throw new RuntimeException(
            'The email verification request is invalid. Contact the site administrator.'
        );
    }

    $now = time();
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = hash_hmac('sha256', $userId . ':' . $code, PEPPER);
    $linkToken = bin2hex(random_bytes(32));
    $linkHash = hash_hmac('sha256', $userId . ':' . $linkToken, PEPPER);
    $verificationUrl = APP_BASE_URL . '/' . $verificationPage . '?token='
        . rawurlencode($linkToken);
    $resendCooldownBoundary = APP_2FA_DEMO_MODE ? $now : $now - 60;
    $stmt = $pdo->prepare(
        'UPDATE users
         SET email_2fa_code_hash = ?, email_2fa_link_hash = ?, email_2fa_expires_at = ?,
             email_2fa_attempts = 0, email_2fa_last_sent_at = ?
         WHERE id = ?
           AND (email_2fa_last_sent_at IS NULL OR email_2fa_last_sent_at <= ?)'
    );
    $stmt->execute([
        $codeHash,
        $linkHash,
        $now + 600,
        $now,
        $userId,
        $resendCooldownBoundary,
    ]);
    $sentSlotClaimed = $stmt->rowCount() === 1;
    $stmt->closeCursor();

    if (!$sentSlotClaimed) {
        return false;
    }

    if (APP_2FA_DEMO_MODE) {
        $_SESSION['dev_email_2fa_code'] = $code;
        return true;
    }

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = APP_SMTP_HOST;
        $mailer->Port = APP_SMTP_PORT;
        $mailer->SMTPAuth = APP_SMTP_USERNAME !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = APP_SMTP_USERNAME;
            $mailer->Password = APP_SMTP_PASSWORD;
        }
        if (APP_SMTP_ENCRYPTION === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (APP_SMTP_ENCRYPTION === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }
        $mailer->Timeout = 10;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom(APP_MAIL_FROM, 'CommunityConnect');
        $mailer->addAddress($email);
        $mailer->Subject = 'Your CommunityConnect verification code';
        $mailer->Body = "Your CommunityConnect verification code is {$code}.\n\n"
            . "It expires in 10 minutes and can only be used once. "
            . "Or open this link in the same browser where you started sign-in to verify without typing the code:\n"
            . $verificationUrl . "\n\n"
            . "The link also expires in 10 minutes and can only be used once. "
            . "If you did not request this code, you can ignore this email.\n";
        $mailer->send();
    } catch (MailerException $e) {
        $clear = $pdo->prepare(
            'UPDATE users
             SET email_2fa_code_hash = NULL, email_2fa_link_hash = NULL,
                 email_2fa_expires_at = NULL,
                 email_2fa_attempts = 0, email_2fa_last_sent_at = NULL
             WHERE id = ? AND email_2fa_code_hash = ?'
        );
        $clear->execute([$userId, $codeHash]);
        error_log(
            'Two-factor verification email could not be sent for user ID '
            . $userId . ': ' . $e->getMessage()
        );

        throw new RuntimeException(
            'We could not send a verification email. Please try again later or contact the site administrator.'
        );
    }

    return true;
}

function consumeEmailTwoFactorCode(PDO $pdo, int $userId, string $code): bool
{
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT email_2fa_code_hash, email_2fa_expires_at, email_2fa_attempts
         FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $challenge = $stmt->fetch();
    $stmt->closeCursor();

    if (
        !$challenge ||
        !is_string($challenge['email_2fa_code_hash']) ||
        $challenge['email_2fa_code_hash'] === '' ||
        (int) $challenge['email_2fa_expires_at'] < time() ||
        (int) $challenge['email_2fa_attempts'] >= 5
    ) {
        $clear = $pdo->prepare(
            'UPDATE users SET email_2fa_code_hash = NULL, email_2fa_link_hash = NULL,
                              email_2fa_expires_at = NULL,
                              email_2fa_attempts = 0
             WHERE id = ?'
        );
        $clear->execute([$userId]);
        $pdo->commit();
        return false;
    }

    $validCodeFormat = preg_match('/\A[0-9]{6}\z/', $code) === 1;
    $expectedHash = $validCodeFormat
        ? hash_hmac('sha256', $userId . ':' . $code, PEPPER)
        : '';
    if (
        !$validCodeFormat ||
        !hash_equals($challenge['email_2fa_code_hash'], $expectedHash)
    ) {
        $increment = $pdo->prepare(
            'UPDATE users SET email_2fa_attempts = email_2fa_attempts + 1
             WHERE id = ? AND email_2fa_attempts < 5'
        );
        $increment->execute([$userId]);
        $pdo->commit();
        return false;
    }

    $consume = $pdo->prepare(
        'UPDATE users
         SET email_2fa_code_hash = NULL, email_2fa_expires_at = NULL,
             email_2fa_link_hash = NULL, email_2fa_attempts = 0
         WHERE id = ? AND email_2fa_code_hash = ?
           AND email_2fa_expires_at >= ? AND email_2fa_attempts < 5'
    );
    $consume->execute([$userId, $challenge['email_2fa_code_hash'], time()]);
    $accepted = $consume->rowCount() === 1;
    $pdo->commit();

    return $accepted;
}

function isValidEmailTwoFactorCode(PDO $pdo, int $userId, string $code): bool
{
    if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT email_2fa_code_hash, email_2fa_expires_at, email_2fa_attempts
         FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $challenge = $stmt->fetch();
    $stmt->closeCursor();

    if (
        !$challenge ||
        !is_string($challenge['email_2fa_code_hash']) ||
        $challenge['email_2fa_code_hash'] === '' ||
        (int) $challenge['email_2fa_expires_at'] < time() ||
        (int) $challenge['email_2fa_attempts'] >= 5
    ) {
        return false;
    }

    $expectedHash = hash_hmac('sha256', $userId . ':' . $code, PEPPER);
    return hash_equals($challenge['email_2fa_code_hash'], $expectedHash);
}

function consumeEmailTwoFactorLink(PDO $pdo, int $userId, string $token): bool
{
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT email_2fa_link_hash, email_2fa_expires_at, email_2fa_attempts
         FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $challenge = $stmt->fetch();
    $stmt->closeCursor();

    if (
        !$challenge ||
        !is_string($challenge['email_2fa_link_hash']) ||
        $challenge['email_2fa_link_hash'] === '' ||
        (int) $challenge['email_2fa_expires_at'] < time() ||
        (int) $challenge['email_2fa_attempts'] >= 5
    ) {
        $clear = $pdo->prepare(
            'UPDATE users SET email_2fa_code_hash = NULL, email_2fa_link_hash = NULL,
                              email_2fa_expires_at = NULL, email_2fa_attempts = 0
             WHERE id = ?'
        );
        $clear->execute([$userId]);
        $pdo->commit();
        return false;
    }

    $validTokenFormat = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
    $expectedHash = $validTokenFormat
        ? hash_hmac('sha256', $userId . ':' . $token, PEPPER)
        : '';
    if (
        !$validTokenFormat ||
        !hash_equals($challenge['email_2fa_link_hash'], $expectedHash)
    ) {
        $increment = $pdo->prepare(
            'UPDATE users SET email_2fa_attempts = email_2fa_attempts + 1
             WHERE id = ? AND email_2fa_attempts < 5'
        );
        $increment->execute([$userId]);
        $pdo->commit();
        return false;
    }

    $consume = $pdo->prepare(
        'UPDATE users
         SET email_2fa_code_hash = NULL, email_2fa_link_hash = NULL,
             email_2fa_expires_at = NULL, email_2fa_attempts = 0
         WHERE id = ? AND email_2fa_link_hash = ?
           AND email_2fa_expires_at >= ? AND email_2fa_attempts < 5'
    );
    $consume->execute([$userId, $challenge['email_2fa_link_hash'], time()]);
    $accepted = $consume->rowCount() === 1;
    $pdo->commit();

    return $accepted;
}

function isValidEmailTwoFactorLink(PDO $pdo, int $userId, string $token): bool
{
    if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT email_2fa_link_hash, email_2fa_expires_at, email_2fa_attempts
         FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $challenge = $stmt->fetch();
    $stmt->closeCursor();

    if (
        !$challenge ||
        !is_string($challenge['email_2fa_link_hash']) ||
        $challenge['email_2fa_link_hash'] === '' ||
        (int) $challenge['email_2fa_expires_at'] < time() ||
        (int) $challenge['email_2fa_attempts'] >= 5
    ) {
        return false;
    }

    $expectedHash = hash_hmac('sha256', $userId . ':' . $token, PEPPER);
    return hash_equals($challenge['email_2fa_link_hash'], $expectedHash);
}
