<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid()) {
    http_response_code(400);
    render_header('Sign-out required', 'Security check', 'This sign-out request could not be verified.');
    ?>
    <section class="panel">
        <?php render_notice('Return to your workspace and use the Sign out button to safely end your session.', 'warning'); ?>
        <a class="btn btn-secondary" href="community.php">Return to CommunityConnect</a>
    </section>
    <?php
    render_footer();
    exit;
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
header('Location: login.php');
exit;
