#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/Upload.php';

use function KkRepoUpload\repository;
use function KkRepoUpload\version;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $call, string $message): void
{
    try { $call(); } catch (Throwable) { check(true, $message); return; }
    throw new RuntimeException($message);
}
function removeTree(string $directory): void
{
    foreach (scandir($directory) as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) removeTree($path);
        else unlink($path);
    }
    rmdir($directory);
}

$root = sys_get_temp_dir() . '/kkrepo-action-tests-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$server = null;
try {
    $repo = repository('https://nexus.example.org/nexus/repository/team-packages/');
    check($repo['endpoint'] === 'https://nexus.example.org/nexus/service/rest/v1/components?repository=team-packages', 'Reverse-proxy context path lost');
    check(repository('https://example.org/repository/pkg')['repository'] === 'https://example.org/repository/pkg/', 'Trailing slash normalization failed');
    foreach (['https://u:p@example.org/repository/pkg/', 'https://example.org/', 'http://example.org/repository/pkg/',
        'https://example.org/repository/pkg/?q=x', 'https://example.org/repository/pkg/#fragment',
        'https://example.org/repository/a%2Fb/'] as $invalid) {
        rejects(fn() => repository($invalid), 'Unsafe repository URL accepted');
    }
    putenv('VERSION=v2.2.6');check(version() === 'v2.2.6', 'Explicit version changed');
    putenv('VERSION=');putenv('UPLOAD_EVENT_NAME=push');putenv('UPLOAD_REF_TYPE=tag');putenv('UPLOAD_REF_NAME=v1.2.3');
    check(version() === 'v1.2.3', 'Tag inference failed');
    putenv('UPLOAD_REF_TYPE=branch');putenv('UPLOAD_REF_NAME=feature/a/b');
    check(version() === 'dev-feature-a-b', 'Nested branch inference failed');
    putenv('UPLOAD_EVENT_NAME=pull_request');rejects(fn() => version(), 'Pull request inferred a version');
    putenv('VERSION=../escape');rejects(fn() => version(), 'Unsafe version accepted');

    $workspace = $root . '/workspace';$package = $workspace . '/packages/example';
    mkdir($package, 0700, true);mkdir($root . '/state', 0700);
    file_put_contents($package . '/composer.json', json_encode([
        'name' => 'acme/example', 'version' => 'v0.1.0',
        'extra' => ['nexus-upload' => ['ignore' => ['legacy.txt']], 'kkrepo-upload' => ['ignore' => ['/^regex-/', '*.json']]],
    ]));
    $original = file_get_contents($package . '/composer.json');
    foreach (['src/Hello.php', 'README.md', 'legacy.txt', 'regex-hidden.txt', 'custom.tmp',
        'vendor/private.php', 'node_modules/large.js', '.git/config', '.github/workflows/ci.yml',
        'auth.json', '.env.local', '.env.production.local', 'composer.lock', 'excluded.json', 'nested/archive.zip'] as $file) {
        if (!is_dir(dirname($package . '/' . $file))) mkdir(dirname($package . '/' . $file), 0700, true);
        file_put_contents($package . '/' . $file, 'fixture');
    }
    file_put_contents($package . '/.nexus', "ignore=old-config.txt\n");
    file_put_contents($package . '/old-config.txt', 'fixture');
    file_put_contents($package . '/.kkrepo', "ignore=new-config.txt\n");
    file_put_contents($package . '/new-config.txt', 'fixture');
    file_put_contents($root . '/outside.txt', 'must not be packaged');
    symlink($root . '/outside.txt', $package . '/outside-link.txt');
    symlink($root, $workspace . '/outside');

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException('Cannot allocate test port: ' . $error);
    $address = stream_socket_get_name($socket, false);fclose($socket);
    $url = 'http://' . $address . '/nexus/repository/test-packages/';
    file_put_contents($root . '/state/mode', 'success');
    $server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
        $pipes, __DIR__, array_merge(getenv(), ['MOCK_ROOT' => $root . '/state']));
    if (!is_resource($server)) throw new RuntimeException('Cannot start local HTTP fixture');
    for ($i = 0; $i < 100; $i++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($socket !== false) { fclose($socket);break; }
        usleep(20000);
    }

    $base = array_merge(getenv(), [
        'GITHUB_WORKSPACE' => $workspace, 'PACKAGE_PATH' => 'packages/example',
        'KKREPO_REPOSITORY' => $url, 'KKREPO_USERNAME' => 'test-user', 'KKREPO_PASSWORD' => 'test-secret',
        'VERSION' => 'v2.2.6', 'KKREPO_IGNORE' => "*.tmp\n*.zip", 'GITHUB_OUTPUT' => $root . '/outputs',
    ]);
    $run = function (string $mode, array $overrides = []) use ($root, $base): array {
        file_put_contents($root . '/state/mode', $mode);
        file_put_contents($root . '/state/requests', '');
        @unlink($root . '/outputs');@unlink($root . '/state/retried');
        $entrypoint = getenv('KKREPO_TEST_ENTRYPOINT');
        $command = $entrypoint ? [$entrypoint] : [PHP_BINARY, __DIR__ . '/../kkrepo-upload.php'];
        $process = proc_open($command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, __DIR__, array_merge($base, $overrides));
        $out = stream_get_contents($pipes[1]);$err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        $code = proc_close($process);
        check(!str_contains($out . $err, 'test-secret'), 'Password leaked into logs');
        return [$code, $out . $err, (string) file_get_contents($root . '/state/requests')];
    };

    [$code, $output, $requests] = $run('success');
    check($code === 0, 'Successful publication failed: ' . $output);
    check(str_contains($requests, 'POST /nexus/service/rest/v1/components?repository=test-packages'), 'Wrong endpoint/method');
    check(!str_contains($requests, 'packages/upload'), 'Legacy API was used');
    check(str_contains($output, 'Verified publication: acme/example v2.2.6'), 'Success not verified');
    $outputs = file_get_contents($root . '/outputs');
    foreach (['package-name=acme/example', 'version=v2.2.6', 'archive-sha256=', 'dist-url='] as $field) check(str_contains($outputs, $field), 'Missing action output: ' . $field);
    check(file_get_contents($package . '/composer.json') === $original, 'Source composer.json was modified');
    $zip = new ZipArchive();check($zip->open($root . '/state/uploaded.zip', ZipArchive::CHECKCONS) === true, 'ZIP is inconsistent');
    $json = json_decode($zip->getFromName('composer.json'), true);
    check($json['version'] === 'v2.2.6', 'Archive composer version differs from upload');
    foreach (['composer.json', 'src/Hello.php', 'README.md'] as $file) check($zip->locateName($file) !== false, 'Required package file missing');
    foreach (['legacy.txt', 'regex-hidden.txt', 'custom.tmp', 'vendor/private.php', 'node_modules/large.js',
        '.git/config', '.github/workflows/ci.yml', 'auth.json', '.env.local', '.env.production.local',
        'composer.lock', 'excluded.json', 'nested/archive.zip', 'outside-link.txt', '.nexus', '.kkrepo', 'old-config.txt', 'new-config.txt'] as $file) {
        check($zip->locateName($file) === false, 'Excluded file was packaged: ' . $file);
    }
    $zip->close();
    check(glob($package . '/*.zip') === [], 'Generated ZIP left in checkout');
    $firstHash = hash_file('sha256', $root . '/state/uploaded.zip');
    touch($package . '/src/Hello.php', time() - 3600);
    [$code, $output] = $run('success');
    check($code === 0 && hash_file('sha256', $root . '/state/uploaded.zip') === $firstHash, 'Archive depends on source file timestamps');

    foreach (['401', '403', '404', '405', '409', '413', '500', 'redirect-upload', 'missing-version', 'corrupt', 'bad-json', 'foreign-url', 'same-origin-outside', 'redirect-download'] as $mode) {
        [$code, $output, $requests] = $run($mode);
        check($code !== 0, 'Failure incorrectly reported success: ' . $mode);
        check(!file_exists($root . '/outputs'), 'Failed publication wrote success outputs: ' . $mode);
        check(substr_count($requests, 'POST ') === 1, 'Upload was retried: ' . $mode);
        if (in_array($mode, ['foreign-url', 'same-origin-outside'], true)) check(substr_count($requests, 'GET ') === 1, 'Unsafe dist URL was requested');
        echo 'PASS rejection: ' . $mode . "\n";
    }
    [$code, $output] = $run('retry-read');check($code === 0, 'Transient GET error was not retried: ' . $output);
    [$code, $output, $requests] = $run('success', ['VERSION' => 'dev-feature-a-b']);
    check($code === 0 && str_contains($requests, 'acme/example~dev.json'), 'Development metadata endpoint incorrect: ' . $output);
    foreach ([['PACKAGE_PATH' => '../..'], ['PACKAGE_PATH' => 'outside'], ['KKREPO_PASSWORD' => ''], ['VERSION' => 'bad version']] as $override) {
        [$code, $output, $requests] = $run('success', $override);
        check($code !== 0 && $requests === '', 'Invalid input reached upload endpoint');
    }
    file_put_contents($package . '/composer.json', '{invalid');
    [$code, $output, $requests] = $run('success');check($code !== 0 && $requests === '', 'Invalid JSON reached upload endpoint');
    file_put_contents($package . '/composer.json', $original);
    file_put_contents($package . '/composer.json', json_encode(['name' => 'Invalid/Package']));
    [$code, $output, $requests] = $run('success');check($code !== 0 && $requests === '', 'Invalid Composer name reached upload endpoint');
    unlink($package . '/composer.json');
    [$code, $output, $requests] = $run('success');check($code !== 0 && $requests === '', 'Missing composer.json reached upload endpoint');
    file_put_contents($package . '/composer.json', $original);
    [$code, $output, $requests] = $run('success', ['KKREPO_IGNORE' => '/[broken/']);
    check($code !== 0 && $requests === '', 'Invalid ignore expression reached upload endpoint');
    [$code, $output, $requests] = $run('success', ['KKREPO_USERNAME' => 'invalid:user']);
    check($code !== 0 && $requests === '', 'Invalid Basic Auth username reached upload endpoint');
    mkdir($package . '/too-many-files');
    for ($i = 0; $i < 20000; $i++) file_put_contents($package . '/too-many-files/' . $i . '.txt', 'x');
    [$code, $output, $requests] = $run('success');
    check($code !== 0 && $requests === '' && str_contains($output, '20,000-file'), 'Oversized archive reached upload endpoint');
    echo "PASS: $checks checks; real multipart HTTP, ZIP contents, source preservation, metadata, checksum, errors, retries and credentials.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server);proc_close($server); }
    removeTree($root);
}
