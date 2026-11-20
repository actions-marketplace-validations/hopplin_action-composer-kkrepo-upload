<?php
declare(strict_types=1);

$root = getenv('MOCK_ROOT');
$mode = trim((string) file_get_contents($root . '/mode'));
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
file_put_contents($root . '/requests', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
header('Content-Type: application/json');
if ($auth !== 'Basic ' . base64_encode('test-user:test-secret')) {
    http_response_code(401);
    echo '{"message":"unauthorized"}';
    return;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/nexus/service/rest/v1/components') {
    $status = match ($mode) {
        '401' => 401, '403' => 403, '404' => 404, '405' => 405,
        '409' => 409, '413' => 413, '500' => 500, 'redirect-upload' => 302,
        default => 204,
    };
    if ($status !== 204) {
        http_response_code($status);
        if ($status === 302) header('Location: http://example.invalid/steal');
        echo '{"message":"simulated rejection"}';
        return;
    }
    if (($_GET['repository'] ?? '') !== 'test-packages'
        || !isset($_FILES['asset']) || $_FILES['asset']['error'] !== UPLOAD_ERR_OK
        || !isset($_POST['version'], $_POST['name'])) {
        http_response_code(400);
        echo '{"message":"invalid multipart upload"}';
        return;
    }
    move_uploaded_file($_FILES['asset']['tmp_name'], $root . '/uploaded.zip');
    $zip = new ZipArchive();
    if ($zip->open($root . '/uploaded.zip') !== true) { http_response_code(400); return; }
    $composer = json_decode($zip->getFromName('composer.json'), true);
    $zip->close();
    if ($composer['version'] !== $_POST['version'] || $composer['name'] !== $_POST['name']) {
        http_response_code(400);
        return;
    }
    file_put_contents($root . '/upload.json', json_encode($_POST));
    http_response_code(204);
    return;
}
$upload = json_decode((string) @file_get_contents($root . '/upload.json'), true) ?? [];
if (str_starts_with($path, '/nexus/repository/test-packages/p2/')) {
    if ($mode === 'retry-read' && !file_exists($root . '/retried')) {
        touch($root . '/retried'); http_response_code(503); return;
    }
    if ($mode === 'bad-json') { echo '<html>proxy error</html>'; return; }
    $url = 'http://' . $_SERVER['HTTP_HOST'] . '/nexus/repository/test-packages/dist.zip';
    if ($mode === 'foreign-url') $url = 'http://example.invalid/steal.zip';
    if ($mode === 'same-origin-outside') $url = 'http://' . $_SERVER['HTTP_HOST'] . '/admin/steal.zip';
    $version = [
        'name' => $upload['name'], 'version' => $upload['version'],
        'dist' => ['url' => $url, 'type' => 'zip'],
    ];
    echo json_encode(['packages' => [$upload['name'] => $mode === 'missing-version' ? [] : [$version]]]);
    return;
}
if ($path === '/nexus/repository/test-packages/dist.zip') {
    if ($mode === 'redirect-download') {
        http_response_code(302);header('Location: http://example.invalid/steal');return;
    }
    header('Content-Type: application/zip');
    if ($mode === 'corrupt') echo 'corrupt archive';
    else readfile($root . '/uploaded.zip');
    return;
}
http_response_code(404);
echo '{"message":"unknown route"}';
