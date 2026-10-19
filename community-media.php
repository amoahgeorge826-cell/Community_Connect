<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$mediaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($mediaId === false || $mediaId === null) {
    http_response_code(404);
    exit;
}

$mediaQuery = $pdo->prepare('SELECT file_name, mime_type FROM community_report_media WHERE id = ?');
$mediaQuery->execute([$mediaId]);
$media = $mediaQuery->fetch();
$allowedTypes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'video/mp4', 'video/webm', 'video/quicktime',
    'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/webm', 'audio/wav', 'audio/x-wav',
];
if (
    !$media ||
    !is_string($media['file_name']) ||
    !preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|gif|webp|mp4|webm|mov|mp3|m4a|aac|ogg|wav)\z/', $media['file_name']) ||
    !in_array($media['mime_type'], $allowedTypes, true)
) {
    http_response_code(404);
    exit;
}

$path = COMMUNITY_MEDIA_DIR . DIRECTORY_SEPARATOR . $media['file_name'];
$fileSize = is_file($path) ? filesize($path) : false;
$stream = $fileSize === false ? false : fopen($path, 'rb');
if ($fileSize === false || $stream === false) {
    error_log('Community report media is missing or unreadable: ' . $media['file_name']);
    http_response_code(404);
    exit;
}

$start = 0;
$end = $fileSize - 1;
$rangeHeader = (string) ($_SERVER['HTTP_RANGE'] ?? '');
if ($rangeHeader !== '') {
    if (!preg_match('/\Abytes=(\d*)-(\d*)\z/', trim($rangeHeader), $range)) {
        fclose($stream);
        header('Content-Range: bytes */' . $fileSize);
        http_response_code(416);
        exit;
    }
    if ($range[1] === '') {
        $suffixLength = (int) $range[2];
        if ($suffixLength < 1) {
            fclose($stream);
            header('Content-Range: bytes */' . $fileSize);
            http_response_code(416);
            exit;
        }
        $start = max(0, $fileSize - $suffixLength);
    } else {
        $start = (int) $range[1];
        $end = $range[2] === '' ? $end : min((int) $range[2], $end);
    }
    if ($start > $end || $start >= $fileSize || fseek($stream, $start) !== 0) {
        fclose($stream);
        header('Content-Range: bytes */' . $fileSize);
        http_response_code(416);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
}

$length = $end - $start + 1;
header('Content-Type: ' . $media['mime_type']);
header('Content-Length: ' . $length);
header('Content-Disposition: inline; filename="community-report-media.' . pathinfo($media['file_name'], PATHINFO_EXTENSION) . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

while ($length > 0 && !feof($stream)) {
    $chunk = fread($stream, min(8192, $length));
    if ($chunk === false) {
        error_log('Community report media stream failed: ' . $media['file_name']);
        break;
    }
    echo $chunk;
    $length -= strlen($chunk);
}
fclose($stream);
