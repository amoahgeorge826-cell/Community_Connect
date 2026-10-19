<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_login();

$userQuery = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ?');
$userQuery->execute([(int) $_SESSION['user_id']]);
$currentUser = $userQuery->fetch();
if (!$currentUser || !in_array($currentUser['role'], ['Admin', 'User'], true)) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$_SESSION['username'] = $currentUser['username'];
$_SESSION['role'] = $currentUser['role'];
$isAdmin = $currentUser['role'] === 'Admin';
$categories = ['Roads & sidewalks', 'Streetlights', 'Waste & sanitation', 'Water & drainage', 'Parks & public spaces', 'Public safety', 'Other'];
$statuses = ['Submitted', 'Acknowledged', 'In Progress', 'Resolved', 'Closed'];
$urgencies = ['Low', 'Normal', 'High'];
$error = '';
$formCategory = is_string($_POST['category'] ?? null) ? $_POST['category'] : '';
$formTitle = is_string($_POST['title'] ?? null) ? $_POST['title'] : '';
$formDescription = is_string($_POST['description'] ?? null) ? $_POST['description'] : '';
$formLocation = is_string($_POST['location'] ?? null) ? $_POST['location'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!csrf_is_valid()) {
        $error = 'Your request expired or could not be verified. Refresh this page and try again.';
    } elseif ($action === 'create_report') {
        $category = is_string($_POST['category'] ?? null) ? $_POST['category'] : '';
        $title = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
        $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
        $location = trim(is_string($_POST['location'] ?? null) ? $_POST['location'] : '');
        $urgency = is_string($_POST['urgency'] ?? null) ? $_POST['urgency'] : '';
        $latitudeInput = is_string($_POST['latitude'] ?? null) ? trim($_POST['latitude']) : '';
        $longitudeInput = is_string($_POST['longitude'] ?? null) ? trim($_POST['longitude']) : '';
        $latitude = null;
        $longitude = null;
        $pendingMedia = [];
        $uploadError = '';
        $upload = $_FILES['attachments'] ?? null;

        if ($upload !== null) {
            $uploadFields = ['name', 'tmp_name', 'error', 'size'];
            foreach ($uploadFields as $field) {
                if (!isset($upload[$field]) || !is_array($upload[$field])) {
                    $uploadError = 'The selected media could not be read. Please choose the files again.';
                    break;
                }
            }
            if ($uploadError === '') {
                $fileCount = count($upload['name']);
                if ($fileCount > 4) {
                    $uploadError = 'Attach up to 4 photos, videos, or voice recordings per report.';
                } else {
                    $allowedMedia = [
                        'image/jpeg' => ['extension' => 'jpg', 'kind' => 'image', 'limit' => 10 * 1024 * 1024],
                        'image/png' => ['extension' => 'png', 'kind' => 'image', 'limit' => 10 * 1024 * 1024],
                        'image/gif' => ['extension' => 'gif', 'kind' => 'image', 'limit' => 10 * 1024 * 1024],
                        'image/webp' => ['extension' => 'webp', 'kind' => 'image', 'limit' => 10 * 1024 * 1024],
                        'video/mp4' => ['extension' => 'mp4', 'kind' => 'video', 'limit' => 25 * 1024 * 1024],
                        'video/webm' => ['extension' => 'webm', 'kind' => 'video', 'limit' => 25 * 1024 * 1024],
                        'video/quicktime' => ['extension' => 'mov', 'kind' => 'video', 'limit' => 25 * 1024 * 1024],
                        'audio/mpeg' => ['extension' => 'mp3', 'kind' => 'audio', 'limit' => 15 * 1024 * 1024],
                        'audio/mp4' => ['extension' => 'm4a', 'kind' => 'audio', 'limit' => 15 * 1024 * 1024],
                        'audio/aac' => ['extension' => 'aac', 'kind' => 'audio', 'limit' => 15 * 1024 * 1024],
                        'audio/ogg' => ['extension' => 'ogg', 'kind' => 'audio', 'limit' => 15 * 1024 * 1024],
                        'audio/webm' => ['extension' => 'webm', 'kind' => 'audio', 'limit' => 15 * 1024 * 1024],
                        'audio/wav' => ['extension' => 'wav', 'kind' => 'audio', 'limit' => 15 * 1024 * 1024],
                        'audio/x-wav' => ['extension' => 'wav', 'kind' => 'audio', 'limit' => 15 * 1024 * 1024],
                    ];
                    $totalSize = 0;
                    $finfo = class_exists(finfo::class) ? new finfo(FILEINFO_MIME_TYPE) : null;
                    if (!$finfo) {
                        $uploadError = 'Media uploads are unavailable on this server. Please contact the administrator.';
                    } else {
                        for ($index = 0; $index < $fileCount; $index++) {
                            $uploadErrorCode = (int) ($upload['error'][$index] ?? UPLOAD_ERR_NO_FILE);
                            if ($uploadErrorCode === UPLOAD_ERR_NO_FILE) {
                                continue;
                            }
                            $temporaryPath = is_string($upload['tmp_name'][$index] ?? null) ? $upload['tmp_name'][$index] : '';
                            if ($uploadErrorCode !== UPLOAD_ERR_OK || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
                                $uploadError = 'One of the selected files could not be uploaded. Please try again.';
                                break;
                            }
                            $mimeType = $finfo->file($temporaryPath);
                            $mediaRule = is_string($mimeType) ? ($allowedMedia[$mimeType] ?? null) : null;
                            $fileSize = filesize($temporaryPath);
                            if (!$mediaRule || $fileSize === false || $fileSize < 1) {
                                $uploadError = 'Choose a supported image, video, or audio recording.';
                                break;
                            }
                            if ($fileSize > $mediaRule['limit']) {
                                $uploadError = 'A selected file is larger than the allowed size (10 MB for images, 15 MB for audio, or 25 MB for video).';
                                break;
                            }
                            $totalSize += $fileSize;
                            if ($totalSize > 40 * 1024 * 1024) {
                                $uploadError = 'The total size of report attachments must be 40 MB or less.';
                                break;
                            }
                            $pendingMedia[] = [
                                'tmp_name' => $temporaryPath,
                                'extension' => $mediaRule['extension'],
                                'kind' => $mediaRule['kind'],
                                'mime_type' => $mimeType,
                                'byte_size' => $fileSize,
                            ];
                        }
                    }
                }
            }
        }

        if ($latitudeInput !== '' || $longitudeInput !== '') {
            if (
                $latitudeInput === '' ||
                $longitudeInput === '' ||
                !is_numeric($latitudeInput) ||
                !is_numeric($longitudeInput) ||
                (float) $latitudeInput < -90 ||
                (float) $latitudeInput > 90 ||
                (float) $longitudeInput < -180 ||
                (float) $longitudeInput > 180
            ) {
                $uploadError = 'The GPS location is invalid. Use the location button again or enter a street address.';
            } else {
                $latitude = (float) $latitudeInput;
                $longitude = (float) $longitudeInput;
            }
        }
        if ($location === '' && $latitude !== null && $longitude !== null) {
            $location = 'GPS coordinates ' . number_format($latitude, 5, '.', '') . ', ' . number_format($longitude, 5, '.', '');
        } elseif ($location === '' && $pendingMedia) {
            $location = 'Location not provided';
        }
        if ($pendingMedia && $title === '') {
            $mediaLabel = count(array_unique(array_column($pendingMedia, 'kind'))) === 1
                ? match ($pendingMedia[0]['kind']) {
                    'image' => 'Photo report',
                    'video' => 'Video report',
                    'audio' => 'Voice report',
                }
                : 'Media report';
            $title = $mediaLabel . ': ' . $category;
        }
        if ($pendingMedia && $description === '') {
            $description = 'This issue was reported using attached ' . implode(
                ', ',
                array_unique(array_map(
                    static fn(array $media): string => match ($media['kind']) {
                        'image' => 'photo(s)',
                        'video' => 'video(s)',
                        'audio' => 'voice recording(s)',
                    },
                    $pendingMedia
                ))
            ) . '.';
        }

        $formCategory = $category;
        $formTitle = $title;
        $formDescription = $description;
        $formLocation = $location;

        if ($uploadError !== '') {
            $error = $uploadError;
        } elseif (!in_array($category, $categories, true)) {
            $error = 'Choose a valid issue category.';
        } elseif ($title === '' || mb_strlen($title, 'UTF-8') > 120) {
            $error = 'Enter a title between 1 and 120 characters.';
        } elseif ($description === '' || mb_strlen($description, 'UTF-8') > 5000) {
            $error = 'Describe the issue in 1 to 5,000 characters.';
        } elseif ($location === '' || mb_strlen($location, 'UTF-8') > 250) {
            $error = 'Enter a location between 1 and 250 characters, or attach media to submit without one.';
        } elseif (!in_array($urgency, $urgencies, true)) {
            $error = 'Choose a valid urgency level.';
        } else {
            $storedMedia = [];
            try {
                if ($pendingMedia) {
                    if (!is_dir(COMMUNITY_MEDIA_DIR) && !mkdir(COMMUNITY_MEDIA_DIR, 0700, true) && !is_dir(COMMUNITY_MEDIA_DIR)) {
                        throw new RuntimeException('Community media directory could not be created.');
                    }
                    foreach ($pendingMedia as $media) {
                        $fileName = bin2hex(random_bytes(16)) . '.' . $media['extension'];
                        $destination = COMMUNITY_MEDIA_DIR . DIRECTORY_SEPARATOR . $fileName;
                        if (!move_uploaded_file($media['tmp_name'], $destination)) {
                            throw new RuntimeException('A community report attachment could not be stored.');
                        }
                        $storedMedia[] = $media + ['file_name' => $fileName];
                    }
                }

                $pdo->beginTransaction();
                $insert = $pdo->prepare(
                    'INSERT INTO community_reports (reporter_id, reporter_name, category, title, description, location, urgency, latitude, longitude)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    (int) $currentUser['id'],
                    $currentUser['username'],
                    $category,
                    $title,
                    $description,
                    $location,
                    $urgency,
                    $latitude,
                    $longitude,
                ]);
                $reportId = (int) $pdo->lastInsertId();
                $mediaInsert = $pdo->prepare(
                    'INSERT INTO community_report_media (report_id, file_name, media_kind, mime_type, byte_size) VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($storedMedia as $media) {
                    $mediaInsert->execute([
                        $reportId,
                        $media['file_name'],
                        $media['kind'],
                        $media['mime_type'],
                        $media['byte_size'],
                    ]);
                }
                $pdo->commit();
                $_SESSION['community_flash'] = 'Your report has been submitted. The community team can now review it.';
                header('Location: community.php#issues');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                foreach ($storedMedia as $media) {
                    $storedPath = COMMUNITY_MEDIA_DIR . DIRECTORY_SEPARATOR . $media['file_name'];
                    if (is_file($storedPath) && !unlink($storedPath)) {
                        error_log('Community report attachment cleanup failed: ' . $storedPath);
                    }
                }
                error_log('Community report creation failed: ' . $e->getMessage());
                $error = 'The report could not be submitted. Please try again.';
            }
        }
    } elseif ($action === 'comment') {
        $reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
        $message = trim(is_string($_POST['message'] ?? null) ? $_POST['message'] : '');
        if ($reportId === false || $reportId === null) {
            $error = 'Choose a valid community report.';
        } elseif ($message === '' || mb_strlen($message, 'UTF-8') > 2000) {
            $error = 'Enter an update between 1 and 2,000 characters.';
        } else {
            $exists = $pdo->prepare('SELECT id FROM community_reports WHERE id = ?');
            $exists->execute([$reportId]);
            if (!$exists->fetchColumn()) {
                $error = 'That community report is no longer available.';
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO community_updates (report_id, user_id, author_name, update_type, message)
                     VALUES (?, ?, ?, 'Comment', ?)"
                );
                $insert->execute([$reportId, (int) $currentUser['id'], $currentUser['username'], $message]);
                $pdo->prepare('UPDATE community_reports SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$reportId]);
                header('Location: community.php#report-' . $reportId);
                exit;
            }
        }
    } elseif ($action === 'update_status' && $isAdmin) {
        $reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        $message = trim(is_string($_POST['message'] ?? null) ? $_POST['message'] : '');
        if ($reportId === false || $reportId === null) {
            $error = 'Choose a valid community report.';
        } elseif (!in_array($newStatus, $statuses, true)) {
            $error = 'Choose a valid report status.';
        } elseif ($message === '' || mb_strlen($message, 'UTF-8') > 2000) {
            $error = 'Add a response of up to 2,000 characters when changing a report status.';
        } else {
            $pdo->beginTransaction();
            try {
                $find = $pdo->prepare('SELECT status FROM community_reports WHERE id = ?');
                $find->execute([$reportId]);
                $oldStatus = $find->fetchColumn();
                if (!is_string($oldStatus)) {
                    $pdo->rollBack();
                    $error = 'That community report is no longer available.';
                } else {
                    $pdo->prepare('UPDATE community_reports SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                        ->execute([$newStatus, $reportId]);
                    $pdo->prepare(
                        "INSERT INTO community_updates (report_id, user_id, author_name, update_type, message, old_status, new_status)
                         VALUES (?, ?, ?, 'Status', ?, ?, ?)"
                    )->execute([
                        $reportId,
                        (int) $currentUser['id'],
                        $currentUser['username'],
                        $message,
                        $oldStatus,
                        $newStatus,
                    ]);
                    $pdo->commit();
                    header('Location: community.php#report-' . $reportId);
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Community report status update failed: ' . $e->getMessage());
                $error = 'The report could not be updated. Please try again.';
            }
        }
    } else {
        http_response_code(403);
        $error = 'This action is not available to your account.';
    }
}

$flash = is_string($_SESSION['community_flash'] ?? null) ? $_SESSION['community_flash'] : '';
unset($_SESSION['community_flash']);
$filterStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$filterCategory = is_string($_GET['category'] ?? null) ? $_GET['category'] : '';
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$conditions = [];
$parameters = [];
if (in_array($filterStatus, $statuses, true)) {
    $conditions[] = 'status = ?';
    $parameters[] = $filterStatus;
}
if (in_array($filterCategory, $categories, true)) {
    $conditions[] = 'category = ?';
    $parameters[] = $filterCategory;
}
if ($search !== '') {
    $conditions[] = '(title LIKE ? OR description LIKE ? OR location LIKE ?)';
    array_push($parameters, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$reportsQuery = $pdo->prepare(
    "SELECT id, reporter_name, category, title, description, location, latitude, longitude, urgency, status, created_at, updated_at
     FROM community_reports $where ORDER BY
        CASE status WHEN 'Submitted' THEN 0 WHEN 'Acknowledged' THEN 1 WHEN 'In Progress' THEN 2 ELSE 3 END,
        updated_at DESC LIMIT 100"
);
$reportsQuery->execute($parameters);
$reports = $reportsQuery->fetchAll();
$updatesByReport = [];
$mediaByReport = [];
if ($reports) {
    $reportIds = array_column($reports, 'id');
    $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
    $mediaQuery = $pdo->prepare(
        "SELECT id, report_id, media_kind, mime_type FROM community_report_media
         WHERE report_id IN ($placeholders) ORDER BY id ASC"
    );
    $mediaQuery->execute($reportIds);
    foreach ($mediaQuery->fetchAll() as $media) {
        $mediaByReport[(int) $media['report_id']][] = $media;
    }
    $updatesQuery = $pdo->prepare(
        "SELECT report_id, author_name, update_type, message, old_status, new_status, created_at
         FROM community_updates WHERE report_id IN ($placeholders) ORDER BY created_at ASC LIMIT 500"
    );
    $updatesQuery->execute($reportIds);
    foreach ($updatesQuery->fetchAll() as $update) {
        $updatesByReport[(int) $update['report_id']][] = $update;
    }
}
$newsVideos = $pdo->query(
    "SELECT media.id, media.report_id, media.mime_type, reports.title, reports.category, reports.status
     FROM community_report_media AS media
     JOIN community_reports AS reports ON reports.id = media.report_id
     WHERE media.media_kind = 'video'
     ORDER BY media.created_at DESC, media.id DESC LIMIT 1"
)->fetchAll();
$summary = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status IN ('Submitted', 'Acknowledged', 'In Progress') THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) AS resolved
     FROM community_reports"
)->fetch();

render_header('CommunityConnect', 'Community problem reporting', 'Report local issues, follow progress, and work together with your community response team.');
?>
<div class="content-stack community-workspace">
    <?php render_notice($flash, 'success'); ?>
    <?php render_notice($error, 'error'); ?>
    <section class="community-hero" aria-labelledby="community-hero-title">
        <?php if ($newsVideos): ?>
            <video class="community-hero-video" data-background-video autoplay muted loop playsinline preload="metadata" aria-hidden="true">
                <source src="community-media.php?id=<?php echo (int) $newsVideos[0]['id']; ?>" type="<?php echo h($newsVideos[0]['mime_type']); ?>">
            </video>
            <div class="community-video-shade" aria-hidden="true"></div>
            <button class="community-video-toggle" type="button" data-video-toggle aria-label="Pause community background video">Pause video</button>
        <?php endif; ?>
        <div class="community-hero-copy">
            <span class="hero-kicker"><span class="hero-live-dot" aria-hidden="true"></span><?php echo $newsVideos ? 'Community video update · ' . h($newsVideos[0]['category']) : 'Your neighborhood, moving forward'; ?></span>
            <h2 id="community-hero-title">Small reports.<br><span>Real community impact.</span></h2>
            <p>From a streetlight that needs fixing to a road that needs attention, tell your community what’s happening and follow the response every step of the way.</p>
            <div class="hero-actions">
                <a class="btn hero-primary" href="#report-form">Report a problem <span aria-hidden="true">↗</span></a>
                <a class="hero-secondary" href="#issues">Explore community reports <span aria-hidden="true">↓</span></a>
            </div>
            <div class="hero-trust"><span class="hero-avatars" aria-hidden="true"><i>J</i><i>M</i><i>A</i></span><span>Better neighborhoods start when neighbors speak up.</span></div>
        </div>
        <div class="community-hero-art">
            <img src="assets/community-hero.svg" alt="Neighbors and a community response worker improving a local street together" fetchpriority="high">
            <span class="hero-art-note"><span class="hero-art-check" aria-hidden="true">✓</span><span><strong>Every voice matters</strong><small>See progress. Make a difference.</small></span></span>
        </div>
    </section>

    <section class="issue-discovery" aria-labelledby="issue-discovery-title" data-reveal>
        <div class="discovery-heading">
            <div><span class="metric-label">HOW CAN WE HELP?</span><h2 id="issue-discovery-title">Choose what needs attention</h2></div>
            <span class="discovery-hint">Choose a topic to start a report <span aria-hidden="true">↗</span></span>
        </div>
        <div class="issue-category-grid">
            <?php foreach ($categories as $category): ?>
                <button class="issue-category-card" type="button" data-category-select="<?php echo h($category); ?>" aria-pressed="<?php echo $formCategory === $category ? 'true' : 'false'; ?>" data-reveal>
                    <span class="issue-category-art" aria-hidden="true"></span>
                    <span class="issue-category-name"><?php echo h($category); ?></span>
                    <span class="issue-category-arrow" aria-hidden="true">↗</span>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel community-welcome" aria-label="Community response overview" data-reveal>
        <div class="panel-heading">
            <div><span class="metric-label">COMMUNITY PULSE</span><h2>Good to see you, <?php echo h($currentUser['username']); ?></h2><p>Here’s what your neighbors are working on together.</p></div>
            <span class="role-badge <?php echo $isAdmin ? 'role-badge-admin' : 'role-badge-user'; ?>">
                <span class="status-dot" aria-hidden="true"></span><?php echo $isAdmin ? 'Response team' : 'Community member'; ?>
            </span>
        </div>
        <div class="metric-grid">
            <article class="metric-card pulse-total"><span class="metric-icon" aria-hidden="true">◉</span><span class="metric-label">COMMUNITY REPORTS</span><div class="metric-value"><?php echo h((string) $summary['total']); ?></div><p class="metric-caption">Neighbors making issues visible</p></article>
            <article class="metric-card pulse-active"><span class="metric-icon" aria-hidden="true">↗</span><span class="metric-label">NEEDS ATTENTION</span><div class="metric-value"><?php echo h((string) ($summary['active'] ?? 0)); ?></div><p class="metric-caption">Being reviewed or worked on</p></article>
            <article class="metric-card pulse-resolved"><span class="metric-icon" aria-hidden="true">✓</span><span class="metric-label">RESOLVED</span><div class="metric-value"><?php echo h((string) ($summary['resolved'] ?? 0)); ?></div><p class="metric-caption">Progress made by the community</p></article>
        </div>
    </section>

    <section class="panel report-form-panel" id="report-form" data-reveal>
        <div class="panel-heading">
            <div class="report-form-title"><span class="form-step-badge">01 <span> / 02</span></span><div><span class="metric-label">START A REPORT</span><h2>Let’s make it better.</h2><p>A few details help the right people understand what’s needed.</p></div></div>
            <span class="form-time"><span aria-hidden="true">◷</span> About 2 minutes</span>
        </div>
        <form class="community-form" method="POST" enctype="multipart/form-data" action="community.php#report-form" data-community-report-form>
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="create_report">
            <input type="hidden" name="latitude" value="<?php echo h(is_string($_POST['latitude'] ?? null) ? $_POST['latitude'] : ''); ?>" data-report-latitude>
            <input type="hidden" name="longitude" value="<?php echo h(is_string($_POST['longitude'] ?? null) ? $_POST['longitude'] : ''); ?>" data-report-longitude>
            <div class="form-section-label"><span>01</span><div><strong>What’s the issue?</strong><small>Choose a category and summarize the problem.</small></div></div>
            <div class="community-form-grid">
                <div class="field">
                    <label for="category">Issue type</label>
                    <select id="category" name="category" data-report-category required>
                        <option value="">Choose a category</option>
                        <?php foreach ($categories as $category): ?><option value="<?php echo h($category); ?>" <?php echo $formCategory === $category ? 'selected' : ''; ?>><?php echo h($category); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="urgency">Urgency</label>
                    <select id="urgency" name="urgency" required>
                        <?php foreach ($urgencies as $urgency): ?><option value="<?php echo h($urgency); ?>" <?php echo ($_POST['urgency'] ?? 'Normal') === $urgency ? 'selected' : ''; ?>><?php echo h($urgency); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field"><label for="title">Short summary <span>(optional with media)</span></label><input id="title" name="title" maxlength="120" value="<?php echo h($formTitle); ?>" placeholder="e.g. Streetlight out near the library" data-report-title></div>
            <div class="field report-media-field">
                <label for="attachments">Add a photo, video, or voice recording <span>(optional)</span></label>
                <input id="attachments" name="attachments[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,audio/mpeg,audio/mp4,audio/aac,audio/ogg,audio/webm,audio/wav,audio/x-wav" multiple data-report-attachments>
                <small class="field-hint">You can attach up to 4 files. Images up to 10 MB, audio up to 15 MB, and videos up to 25 MB each. A media-only report does not need a typed summary.</small>
                <div class="recording-actions"><button class="btn btn-secondary" type="button" data-record-audio>Record a voice report</button><span data-recording-status role="status" aria-live="polite">Voice recording is ready when your browser supports microphone access.</span></div>
                <div class="media-preview-list" data-media-preview aria-live="polite"></div>
            </div>
            <div class="form-section-label form-section-location"><span>02</span><div><strong>Where should we look?</strong><small>Help responders find the exact spot.</small></div></div>
            <div class="field"><label for="location">Location <span>(optional with media or GPS)</span></label><div class="location-input-row"><input id="location" name="location" maxlength="250" value="<?php echo h($formLocation); ?>" placeholder="Street, landmark, or neighborhood" data-report-location><button class="btn btn-secondary" type="button" data-use-location>Use my GPS</button></div><small class="field-hint">Your precise coordinates are saved only when you choose Use my GPS. You can also enter a nearby landmark. Reports with media may be submitted without a location.</small><span class="gps-status" data-gps-status role="status" aria-live="polite"></span></div>
            <div class="report-map-preview" data-report-map hidden><iframe title="Map showing the selected GPS location" loading="lazy" referrerpolicy="no-referrer"></iframe><a href="#" target="_blank" rel="noopener noreferrer" data-map-link>Open map</a></div>
            <div class="field"><label for="description">What is happening? <span>(optional with an attachment)</span></label><textarea id="description" name="description" maxlength="5000" rows="4" placeholder="Describe what you noticed and any useful details." data-report-description><?php echo h($formDescription); ?></textarea></div>
            <div class="report-form-footer"><p class="privacy-note"><span class="privacy-icon" aria-hidden="true">⌑</span><span><strong>Your privacy matters.</strong> Reports are visible to signed-in neighbors. Please leave out personal contact details.</span></p><button class="btn report-submit" type="submit">Send my report <span aria-hidden="true">→</span></button></div>
        </form>
    </section>

    <section class="panel" id="issues" data-reveal>
        <div class="panel-heading">
            <div><span class="metric-label">COMMUNITY FEED</span><h2>Reported issues</h2><p>Track progress and share helpful updates with neighbors.</p></div>
            <span class="feed-count"><strong><?php echo count($reports); ?></strong> <?php echo count($reports) === 1 ? 'report' : 'reports'; ?> shown <span>·</span> up to 100</span>
        </div>
        <form class="report-filters" method="GET" action="community.php#issues">
            <div class="field"><label for="search">Search</label><input id="search" name="q" value="<?php echo h($search); ?>" placeholder="Issue, detail, or location"></div>
            <div class="field"><label for="filter-category">Category</label><select id="filter-category" name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?php echo h($category); ?>" <?php echo $filterCategory === $category ? 'selected' : ''; ?>><?php echo h($category); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="filter-status">Status</label><select id="filter-status" name="status"><option value="">All statuses</option><?php foreach ($statuses as $status): ?><option value="<?php echo h($status); ?>" <?php echo $filterStatus === $status ? 'selected' : ''; ?>><?php echo h($status); ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-secondary" type="submit">Filter</button>
        </form>
        <?php if (!$reports): ?>
            <div class="empty-state">No reports match those filters yet. If you have noticed a problem, share the first report with your community.</div>
        <?php else: ?>
            <div class="report-list">
                <?php foreach ($reports as $report): ?>
                    <?php
                    $statusClass = strtolower(str_replace(' ', '-', $report['status']));
                    $statusProgress = array_search($report['status'], $statuses, true);
                    $statusProgress = $statusProgress === false ? 0 : $statusProgress;
                    $reportLatitude = is_numeric($report['latitude']) ? (float) $report['latitude'] : null;
                    $reportLongitude = is_numeric($report['longitude']) ? (float) $report['longitude'] : null;
                    $mapHref = $reportLatitude !== null && $reportLongitude !== null
                        ? 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $reportLatitude)
                            . '&mlon=' . rawurlencode((string) $reportLongitude)
                            . '#map=17/' . rawurlencode((string) $reportLatitude) . '/' . rawurlencode((string) $reportLongitude)
                        : 'https://www.openstreetmap.org/search?query=' . rawurlencode($report['location']);
                    ?>
                    <article class="report-card" id="report-<?php echo (int) $report['id']; ?>" data-reveal>
                        <div class="report-card-heading">
                            <div class="report-card-title"><span class="report-category"><?php echo h($report['category']); ?> <span aria-hidden="true">·</span> <?php echo h(date('M j, Y', strtotime($report['created_at']))); ?></span><h3><?php echo h($report['title']); ?></h3></div>
                            <span class="report-status status-<?php echo h($statusClass); ?>"><span aria-hidden="true"></span><?php echo h($report['status']); ?></span>
                        </div>
                        <p class="report-description"><?php echo nl2br(h($report['description'])); ?></p>
                        <?php if (!empty($mediaByReport[(int) $report['id']])): ?>
                            <div class="report-attachments" aria-label="Report attachments">
                                <?php foreach ($mediaByReport[(int) $report['id']] as $media): ?>
                                    <?php $mediaUrl = 'community-media.php?id=' . (int) $media['id']; ?>
                                    <?php if ($media['media_kind'] === 'image'): ?>
                                        <a class="report-image-link" href="<?php echo h($mediaUrl); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo h($mediaUrl); ?>" alt="Photo attached to this report" loading="lazy"></a>
                                    <?php elseif ($media['media_kind'] === 'video'): ?>
                                        <video class="report-video" controls preload="metadata"><source src="<?php echo h($mediaUrl); ?>" type="<?php echo h($media['mime_type']); ?>">Your browser cannot play this video.</video>
                                    <?php else: ?>
                                        <audio class="report-audio" controls preload="none"><source src="<?php echo h($mediaUrl); ?>" type="<?php echo h($media['mime_type']); ?>">Your browser cannot play this recording.</audio>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="report-meta"><span class="report-location"><span aria-hidden="true">⌖</span><strong><?php echo h($report['location']); ?></strong> <a href="<?php echo h($mapHref); ?>" target="_blank" rel="noopener noreferrer">View map</a></span><span><span class="report-meta-label">Reported by</span> <?php echo h($report['reporter_name']); ?></span><span class="report-urgency-label"><span class="report-meta-label">Priority</span><span class="urgency-<?php echo h(strtolower($report['urgency'])); ?>"><?php echo h($report['urgency']); ?></span></span></div>
                        <ol class="report-progress" aria-label="Report status progress">
                            <?php foreach (['Submitted', 'Acknowledged', 'In Progress', 'Resolved', 'Closed'] as $stepIndex => $stepLabel): ?>
                                <li class="<?php echo $stepIndex < $statusProgress ? 'is-complete' : ($stepIndex === $statusProgress ? 'is-current' : ''); ?>" <?php echo $stepIndex === $statusProgress ? 'aria-current="step"' : ''; ?>>
                                    <span class="progress-marker"><?php echo $stepIndex < $statusProgress ? '✓' : (string) ($stepIndex + 1); ?></span><span class="progress-label"><?php echo h($stepLabel); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <?php if (!empty($updatesByReport[(int) $report['id']])): ?>
                            <div class="report-updates">
                                <h4>Timeline &amp; community updates</h4>
                                <?php foreach ($updatesByReport[(int) $report['id']] as $update): ?>
                                    <div class="report-update">
                                        <span class="update-marker" aria-hidden="true"></span>
                                        <div><p><strong><?php echo h($update['author_name']); ?></strong> <span><?php echo h(date('M j, Y · g:i a', strtotime($update['created_at']))); ?></span></p>
                                            <?php if ($update['update_type'] === 'Status'): ?><span class="update-status">Status: <?php echo h($update['old_status']); ?> → <?php echo h($update['new_status']); ?></span><?php endif; ?>
                                            <div><?php echo nl2br(h($update['message'])); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <form class="admin-response-form" method="POST" action="community.php#report-<?php echo (int) $report['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="update_status"><input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                                <div class="field"><label for="status-<?php echo (int) $report['id']; ?>">Update status</label><select id="status-<?php echo (int) $report['id']; ?>" name="status"><?php foreach ($statuses as $status): ?><option value="<?php echo h($status); ?>" <?php echo $report['status'] === $status ? 'selected' : ''; ?>><?php echo h($status); ?></option><?php endforeach; ?></select></div>
                                <div class="field"><label for="response-<?php echo (int) $report['id']; ?>">Response to the community</label><textarea id="response-<?php echo (int) $report['id']; ?>" name="message" rows="2" maxlength="2000" placeholder="Share what is being done or what happens next." required></textarea></div>
                                <button class="btn btn-secondary" type="submit">Post response &amp; update status</button>
                            </form>
                        <?php else: ?>
                            <form class="community-comment-form" method="POST" action="community.php#report-<?php echo (int) $report['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="comment"><input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                                <div class="field"><label for="comment-<?php echo (int) $report['id']; ?>">Share a helpful update</label><textarea id="comment-<?php echo (int) $report['id']; ?>" name="message" rows="2" maxlength="2000" placeholder="Add information that could help neighbors or responders." required></textarea></div>
                                <button class="btn btn-secondary" type="submit">Post update</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php render_footer(); ?>
