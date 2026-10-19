<?php
declare(strict_types=1);

function runtime_config_storage_dir(): string
{
    return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'secure-login-config';
}

function default_runtime_directory_settings(): array
{
    $backupDirectory = getenv('APP_BACKUP_DIR');
    $mediaDirectory = getenv('APP_COMMUNITY_MEDIA_DIR');

    return [
        'backup_directory' => validate_private_directory_path($backupDirectory !== false && $backupDirectory !== ''
            ? $backupDirectory
            : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'secure-login-backups'),
        'media_directory' => validate_private_directory_path($mediaDirectory !== false && $mediaDirectory !== ''
            ? $mediaDirectory
            : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'communityconnect-media'),
    ];
}

function validate_private_directory_path(string $path): string
{
    $path = trim($path);
    $isAbsolute = DIRECTORY_SEPARATOR === '\\'
        ? preg_match('/\A(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $path) === 1
        : str_starts_with($path, '/');

    if ($path === '' || str_contains($path, "\0") || !$isAbsolute) {
        throw new InvalidArgumentException('Enter an absolute directory path.');
    }

    $documentRootValue = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $documentRoot = $documentRootValue !== '' ? realpath($documentRootValue) : false;
    if ($documentRoot !== false) {
        $resolvedPath = realpath($path);
        if ($resolvedPath === false) {
            $normalizedInput = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            $remainingParts = [];
            $existingParent = $normalizedInput;
            while (!file_exists($existingParent)) {
                array_unshift($remainingParts, basename($existingParent));
                $nextParent = dirname($existingParent);
                if ($nextParent === $existingParent) {
                    break;
                }
                $existingParent = $nextParent;
            }
            $resolvedParent = realpath($existingParent);
            if ($resolvedParent !== false) {
                $resolvedPath = $resolvedParent;
                foreach ($remainingParts as $part) {
                    if ($part === '' || $part === '.') {
                        continue;
                    }
                    if ($part === '..') {
                        $resolvedPath = dirname($resolvedPath);
                        continue;
                    }
                    $resolvedPath .= DIRECTORY_SEPARATOR . $part;
                }
            } else {
                $resolvedPath = $normalizedInput;
            }
        }

        $normalize = static fn(string $value): string => rtrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value),
            DIRECTORY_SEPARATOR
        );
        $normalizedPath = $normalize($resolvedPath);
        $normalizedRoot = $normalize($documentRoot);
        $comparisonPath = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath;
        $comparisonRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedRoot) : $normalizedRoot;
        if (
            $comparisonPath === $comparisonRoot ||
            str_starts_with($comparisonPath, $comparisonRoot . DIRECTORY_SEPARATOR)
        ) {
            throw new InvalidArgumentException('Backup and media directories must be outside the public web directory.');
        }
    }

    return $path;
}

function current_runtime_directory_settings(): array
{
    $settings = default_runtime_directory_settings();
    $activeFile = runtime_config_storage_dir() . DIRECTORY_SEPARATOR . 'directories.json';
    if (!is_file($activeFile)) {
        return $settings;
    }

    $contents = file_get_contents($activeFile);
    $stored = $contents === false ? null : json_decode($contents, true);
    if (
        !is_array($stored) ||
        !is_string($stored['backup_directory'] ?? null) ||
        !is_string($stored['media_directory'] ?? null)
    ) {
        throw new RuntimeException('The saved directory configuration is invalid or unreadable.');
    }

    return [
        'backup_directory' => validate_private_directory_path($stored['backup_directory']),
        'media_directory' => validate_private_directory_path($stored['media_directory']),
    ];
}

function save_runtime_directory_settings(array $settings): void
{
    $settings = [
        'backup_directory' => validate_private_directory_path((string) ($settings['backup_directory'] ?? '')),
        'media_directory' => validate_private_directory_path((string) ($settings['media_directory'] ?? '')),
    ];
    $storageDir = runtime_config_storage_dir();
    if (!is_dir($storageDir) && !mkdir($storageDir, 0700, true) && !is_dir($storageDir)) {
        throw new RuntimeException('The private configuration directory could not be created.');
    }

    $target = $storageDir . DIRECTORY_SEPARATOR . 'directories.json';
    $temporaryFile = tempnam($storageDir, 'directories-');
    if ($temporaryFile === false) {
        throw new RuntimeException('A temporary configuration file could not be created.');
    }

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($temporaryFile, $json, LOCK_EX) === false) {
        unlink($temporaryFile);
        throw new RuntimeException('The directory configuration could not be written.');
    }
    if (!chmod($temporaryFile, 0600)) {
        unlink($temporaryFile);
        throw new RuntimeException('The saved directory configuration could not be secured.');
    }

    if (!rename($temporaryFile, $target)) {
        unlink($temporaryFile);
        throw new RuntimeException('The saved directory configuration could not be activated.');
    }
    if (!chmod($target, 0600)) {
        error_log('The active directory configuration file permissions could not be restricted.');
    }
    @chmod($target, 0600);
}

function create_runtime_config_restore_point(array $settings): string
{
    $settings = [
        'backup_directory' => validate_private_directory_path((string) ($settings['backup_directory'] ?? '')),
        'media_directory' => validate_private_directory_path((string) ($settings['media_directory'] ?? '')),
    ];
    $restoreDir = runtime_config_storage_dir() . DIRECTORY_SEPARATOR . 'restore-points';
    if (!is_dir($restoreDir) && !mkdir($restoreDir, 0700, true) && !is_dir($restoreDir)) {
        throw new RuntimeException('The private restore-point directory could not be created.');
    }

    $id = 'config_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.json';
    $path = $restoreDir . DIRECTORY_SEPARATOR . $id;
    $contents = json_encode([
        'created_at' => date(DATE_ATOM),
        'backup_directory' => $settings['backup_directory'],
        'media_directory' => $settings['media_directory'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('The configuration restore point could not be saved.');
    }
    if (!chmod($path, 0600)) {
        error_log('The configuration restore point file permissions could not be restricted: ' . basename($path));
    }

    return $id;
}

function list_runtime_config_restore_points(): array
{
    $restoreDir = runtime_config_storage_dir() . DIRECTORY_SEPARATOR . 'restore-points';
    if (!is_dir($restoreDir)) {
        return [];
    }
    $files = glob($restoreDir . DIRECTORY_SEPARATOR . 'config_*.json');
    if ($files === false) {
        throw new RuntimeException('Configuration restore points could not be listed.');
    }
    rsort($files, SORT_STRING);

    $restorePoints = [];
    foreach (array_slice($files, 0, 50) as $path) {
        $contents = file_get_contents($path);
        $point = $contents === false ? null : json_decode($contents, true);
        if (
            !is_array($point) ||
            !is_string($point['created_at'] ?? null) ||
            !is_string($point['backup_directory'] ?? null) ||
            !is_string($point['media_directory'] ?? null)
        ) {
            error_log('Ignoring invalid configuration restore point: ' . basename($path));
            continue;
        }
        try {
            $restorePoints[] = [
                'id' => basename($path),
                'created_at' => $point['created_at'],
                'backup_directory' => validate_private_directory_path($point['backup_directory']),
                'media_directory' => validate_private_directory_path($point['media_directory']),
            ];
        } catch (InvalidArgumentException $e) {
            error_log('Ignoring unsafe configuration restore point ' . basename($path) . ': ' . $e->getMessage());
        }
    }

    return $restorePoints;
}

function runtime_config_restore_point(string $id): array
{
    if (!preg_match('/\Aconfig_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}_[a-f0-9]{8}\.json\z/', $id)) {
        throw new InvalidArgumentException('Choose a valid configuration restore point.');
    }

    $path = runtime_config_storage_dir() . DIRECTORY_SEPARATOR . 'restore-points' . DIRECTORY_SEPARATOR . $id;
    $contents = is_file($path) ? file_get_contents($path) : false;
    $point = $contents === false ? null : json_decode($contents, true);
    if (
        !is_array($point) ||
        !is_string($point['backup_directory'] ?? null) ||
        !is_string($point['media_directory'] ?? null)
    ) {
        throw new RuntimeException('The selected configuration restore point is unavailable or invalid.');
    }

    return [
        'backup_directory' => validate_private_directory_path($point['backup_directory']),
        'media_directory' => validate_private_directory_path($point['media_directory']),
    ];
}
