<?php
declare(strict_types=1);

namespace KkRepoUpload;

use CURLFile;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function redact(string $message): string
{
    $password = env('KKREPO_PASSWORD');
    if ($password !== '') {
        $message = str_replace([$password, rawurlencode($password)], '***', $message);
    }
    return str_replace(["\r", "\n"], ' ', $message);
}

/** @return array{repository:string, endpoint:string, name:string, origin:string} */
function repository(string $url): array
{
    $parts = parse_url(trim($url));
    if ($parts === false || !isset($parts['scheme'], $parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('repository must be an absolute hosted repository URL without credentials, query or fragment.');
    }
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true))) {
        throw new RuntimeException('Use HTTPS for repository; HTTP is only supported on loopback for local tests.');
    }
    $path = $parts['path'] ?? '';
    if (!preg_match('~^(.*)/repository/([A-Za-z0-9][A-Za-z0-9._-]*)/?$~D', $path, $match)
        || str_contains($match[1], '..') || str_contains($match[1], '%')) {
        throw new RuntimeException('repository must end with /repository/HOSTED-REPOSITORY/.');
    }
    $origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return [
        'repository' => $origin . $match[1] . '/repository/' . $match[2] . '/',
        'endpoint' => $origin . $match[1] . '/service/rest/v1/components?repository=' . rawurlencode($match[2]),
        'name' => $match[2],
        'origin' => $origin,
    ];
}

function version(): string
{
    $value = trim(env('VERSION'));
    if ($value === '') {
        $event = env('UPLOAD_EVENT_NAME', env('GITHUB_EVENT_NAME'));
        $type = env('UPLOAD_REF_TYPE', env('GITHUB_REF_TYPE'));
        $ref = env('UPLOAD_REF_NAME', env('GITHUB_REF_NAME'));
        if ($event !== 'push' || $ref === '' || !in_array($type, ['branch', 'tag'], true)) {
            throw new RuntimeException('Set version explicitly, or publish from a branch/tag push.');
        }
        $value = ($type === 'branch' ? 'dev-' : '') . $ref;
    }
    $value = str_replace('/', '-', $value);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,199}$/D', $value)) {
        throw new RuntimeException('Version contains unsupported characters or is longer than 200 characters.');
    }
    return $value;
}

function packageDirectory(): string
{
    $workspace = realpath(env('GITHUB_WORKSPACE', getcwd()));
    $relative = env('PACKAGE_PATH');
    if ($workspace === false || str_starts_with($relative, '/') || str_contains($relative, "\0")) {
        throw new RuntimeException('package-path must be relative to the workspace.');
    }
    $directory = realpath($workspace . '/' . $relative);
    if ($directory === false || !is_dir($directory)
        || ($directory !== $workspace && !str_starts_with($directory, $workspace . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('package-path must resolve to a directory inside the workspace.');
    }
    return $directory;
}

/** @return array<string, mixed> */
function composerJson(string $directory): array
{
    $file = $directory . '/composer.json';
    if (!is_file($file) || is_link($file)) {
        throw new RuntimeException('Package directory must contain a regular composer.json file.');
    }
    $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($json) || !is_string($json['name'] ?? null)
        || !preg_match('~^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$~D', $json['name'])) {
        throw new RuntimeException('composer.json must contain a lowercase vendor/package name.');
    }
    return $json;
}

/** @return list<string> */
function ignorePatterns(string $directory, array $composer): array
{
    $patterns = [];
    // Legacy configuration is intentionally read first; new settings can add exclusions.
    foreach (['.nexus', '.kkrepo'] as $file) {
        if (is_file($directory . '/' . $file) && !is_link($directory . '/' . $file)) {
            foreach (file($directory . '/' . $file, FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^\s*ignore\s*=(.*)$/', $line, $m)) $patterns[] = trim($m[1]);
            }
        }
    }
    foreach (['nexus-upload', 'kkrepo-upload'] as $key) {
        $ignore = $composer['extra'][$key]['ignore'] ?? [];
        foreach (is_array($ignore) ? $ignore : [$ignore] as $pattern) {
            if (!is_string($pattern)) throw new RuntimeException('Ignore patterns must be strings.');
            $patterns[] = $pattern;
        }
    }
    $patterns = array_merge($patterns, preg_split('/\r?\n/', env('KKREPO_IGNORE')) ?: []);
    $patterns = array_values(array_filter(array_map('trim', $patterns), fn($p) => $p !== ''));
    foreach ($patterns as $pattern) {
        if (str_starts_with($pattern, '/') && @preg_match($pattern, '') === false) {
            throw new RuntimeException('Invalid regular expression in ignore configuration.');
        }
    }
    return $patterns;
}

function excluded(string $path, array $patterns): bool
{
    foreach (explode('/', trim($path, '/')) as $part) {
        if (in_array($part, ['.git', '.github', '.svn', '.idea', 'vendor', 'node_modules'], true)) return true;
    }
    $name = basename(rtrim($path, '/'));
    if (in_array($name, ['composer.lock', '.nexus', '.kkrepo', '.gitignore', 'auth.json', '.env.local'], true)
        || fnmatch('.env.*.local', $name)) return true;
    foreach ($patterns as $pattern) {
        if (str_starts_with($pattern, '/')) {
            if (preg_match($pattern, $path) === 1) return true;
        } elseif (str_starts_with($path, rtrim($pattern, '/') . '/')
            || fnmatch($pattern, $path) || (!str_contains($pattern, '/') && fnmatch($pattern, $name))) {
            return true;
        }
    }
    return false;
}

/** Creates a ZIP in the supplied temporary directory, without modifying the checkout. */
function archive(string $directory, array $composer, string $version, string $temporary): string
{
    $patterns = ignorePatterns($directory, $composer);
    $paths = [];
    $iterator = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        function ($file) use ($directory, $patterns): bool {
            if ($file->isLink()) return false;
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
            // composer.json is mandatory even with a broad ignore pattern such as *.json.
            return $path === 'composer.json' || !excluded($path . ($file->isDir() ? '/' : ''), $patterns);
        }
    );
    foreach (new RecursiveIteratorIterator($iterator) as $file) {
        if (!$file->isFile()) continue;
        $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
        if (count($paths) > 20000) throw new RuntimeException('Package exceeds kkRepo\'s 20,000-file archive limit. Add exclusions.');
    }
    sort($paths, SORT_STRING);
    $filename = str_replace('/', '-', $composer['name']) . '-' . $version . '.zip';
    $path = $temporary . '/' . $filename;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create package archive.');
    }
    try {
        $composer['version'] = $version;
        foreach ($paths as $relative) {
            $ok = $relative === 'composer.json'
                ? $zip->addFromString($relative, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n")
                : $zip->addFile($directory . '/' . $relative, $relative);
            if (!$ok || !$zip->setMtimeName($relative, 315532800)) {
                throw new RuntimeException('Cannot add a package file to the archive.');
            }
        }
    } finally {
        if (!$zip->close()) throw new RuntimeException('Cannot finish package archive.');
    }
    if (!is_file($path) || filesize($path) === 0) throw new RuntimeException('Package archive is empty.');
    return $path;
}

/** @return array{status:int,body:string} */
function request(string $method, string $url, ?array $form = null, ?string $destination = null): array
{
    $attempts = $method === 'GET' ? 3 : 1;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $ch = curl_init($url);
        $file = $destination === null ? null : fopen($destination, 'wb');
        if ($destination !== null && $file === false) throw new RuntimeException('Cannot create verification download.');
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => env('KKREPO_USERNAME') . ':' . env('KKREPO_PASSWORD'),
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'action-composer-kkrepo-upload/1.0',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
        }
        if ($file !== null) curl_setopt($ch, CURLOPT_FILE, $file);
        else curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (is_resource($file)) fclose($file);
        curl_close($ch);
        if ($errno === 0 && $status >= 200 && $status < 300) {
            return ['status' => $status, 'body' => is_string($body) ? $body : ''];
        }
        if ($attempt < $attempts && ($errno !== 0 || in_array($status, [404, 408, 429, 500, 502, 503, 504], true))) {
            sleep($attempt);
            continue;
        }
        $hint = match ($status) {
            401, 403 => 'Check credentials and repository upload/read permissions.',
            400 => 'Check package metadata and that the target is a Composer HOSTED repository.',
            404 => 'Check the repository URL and published package metadata.',
            405 => 'The endpoint does not accept this method; the legacy Nexus upload API is not supported.',
            409 => 'This package version may already exist; kkRepo does not overwrite Composer versions.',
            413 => 'The archive exceeds a server or proxy size limit.',
            default => 'Check kkRepo and reverse-proxy logs.',
        };
        if ($errno !== 0) {
            $uncertain = $method === 'POST' ? ' The server may have accepted the upload; check before retrying.' : '';
            throw new RuntimeException('Network/TLS error during ' . $method . ' (cURL ' . $errno . ').' . $uncertain);
        }
        throw new RuntimeException($method . ' failed with HTTP ' . $status . '. ' . $hint);
    }
    throw new RuntimeException('HTTP request failed.');
}

/** @return array{package-name:string,version:string,archive-sha256:string,dist-url:string} */
function verify(array $repository, string $name, string $version, string $archive, string $temporary): array
{
    $dev = str_starts_with($version, 'dev-') || str_ends_with($version, '-dev');
    $metadata = $repository['repository'] . 'p2/' . $name . ($dev ? '~dev' : '') . '.json';
    $body = request('GET', $metadata)['body'];
    $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $versions = $json['packages'][$name] ?? [];
    $published = null;
    foreach ($versions as $item) {
        if (($item['version'] ?? null) === $version) { $published = $item; break; }
    }
    if ($published === null) throw new RuntimeException('Upload returned success, but the exact version is absent from hosted Composer metadata.');
    $url = $published['dist']['url'] ?? '';
    // Never forward credentials to a different server or to an arbitrary same-origin path.
    if (!is_string($url) || !str_starts_with($url, $repository['repository'])
        || str_contains($url, '..') || str_contains($url, "\r") || str_contains($url, "\n")) {
        throw new RuntimeException('Published dist URL is missing or outside the configured hosted repository.');
    }
    $download = $temporary . '/verification.zip';
    request('GET', $url, destination: $download);
    $sha256 = hash_file('sha256', $archive);
    if (!hash_equals($sha256, hash_file('sha256', $download))) {
        throw new RuntimeException('Published archive SHA-256 differs from the uploaded archive.');
    }
    return ['package-name' => $name, 'version' => $version, 'archive-sha256' => $sha256, 'dist-url' => $url];
}

function run(): void
{
    foreach (['curl', 'zip'] as $extension) {
        if (!extension_loaded($extension)) throw new RuntimeException('PHP extension required: ' . $extension);
    }
    foreach (['KKREPO_REPOSITORY', 'KKREPO_USERNAME', 'KKREPO_PASSWORD'] as $key) {
        if (env($key) === '') throw new RuntimeException('Missing required setting: ' . $key);
    }
    if (str_contains(env('KKREPO_USERNAME'), ':')) throw new RuntimeException('Username cannot contain a colon.');
    $repository = repository(env('KKREPO_REPOSITORY'));
    $directory = packageDirectory();
    $composer = composerJson($directory);
    $version = version();
    $temporary = sys_get_temp_dir() . '/kkrepo-upload-' . bin2hex(random_bytes(12));
    if (!mkdir($temporary, 0700)) throw new RuntimeException('Cannot create temporary upload directory.');
    try {
        $archive = archive($directory, $composer, $version, $temporary);
        printf("Publishing %s %s to hosted repository %s (%d bytes)\n", $composer['name'], $version, $repository['name'], filesize($archive));
        $response = request('POST', $repository['endpoint'], [
            'asset' => new CURLFile($archive, 'application/zip', basename($archive)),
            'name' => $composer['name'],
            'version' => $version,
        ]);
        printf("Upload accepted (HTTP %d); verifying metadata and downloaded SHA-256...\n", $response['status']);
        $result = verify($repository, $composer['name'], $version, $archive, $temporary);
        if (env('GITHUB_OUTPUT') !== '') {
            $lines = '';
            foreach ($result as $key => $value) {
                if (str_contains($value, "\n") || str_contains($value, "\r")) throw new RuntimeException('Invalid multiline output.');
                $lines .= $key . '=' . $value . "\n";
            }
            if (file_put_contents(env('GITHUB_OUTPUT'), $lines, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Cannot write GitHub action outputs.');
            }
        }
        printf("Verified publication: %s %s\nSHA-256: %s\n", $result['package-name'], $result['version'], $result['archive-sha256']);
    } finally {
        foreach (glob($temporary . '/*') ?: [] as $file) unlink($file);
        rmdir($temporary);
    }
}
