#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/src/Upload.php';

try {
    if ($argc > 1) {
        throw new RuntimeException('Configure the uploader using action inputs or environment variables, not command-line arguments.');
    }
    \KkRepoUpload\run();
} catch (Throwable $error) {
    // Do not log response bodies or cURL diagnostics that could contain credentials.
    $message = \KkRepoUpload\redact($error->getMessage());
    fwrite(STDERR, 'Upload failed: ' . $message . PHP_EOL);
    exit(1);
}
