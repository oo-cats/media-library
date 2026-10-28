<?php

declare(strict_types=1);

// Disposable, non-secret UI fixture. Never run against an external/production forum.
// The fixed credential exists only for this local test account and is deleted by cleanup.
$forum = realpath(__DIR__.'/../../../../../flarum-dev/.local/compat/sites/dev');
if (!$forum || PHP_SAPI !== 'cli') {
    exit(1);
}
$config = require $forum.'/config.php';
if (($config['url'] ?? '') !== 'http://127.0.0.1:8890' || ($config['database']['database'] ?? '') !== 'flarum_compat_dev') {
    throw new RuntimeException('Only the named local compatibility development forum is allowed.');
}
require $forum.'/vendor/autoload.php';
$container = (require $forum.'/site.php')->bootApp()->getContainer();
$db = $container->make(\Illuminate\Database\ConnectionInterface::class);
$statePath = __DIR__.'/../../.local/browser-fixture.json';
$storage = $container->make(\Local\MediaLibrary\PrivateStorage::class);
$action = $argv[1] ?? '';
if ($action === 'prepare') {
    if (is_file($statePath) || \Flarum\User\User::where('username', 'media-library-ui')->exists()) {
        throw new RuntimeException('Fixture already exists; clean it up before preparing again.');
    }
    $user = \Flarum\User\User::register('media-library-ui', 'media-ui@example.test', 'Fixture-only-media-123');
    $user->is_email_confirmed = true;
    $user->save();
    $image = imagecreatetruecolor(240, 160);
    $blue = imagecolorallocate($image, 66, 92, 154);
    $white = imagecolorallocate($image, 240, 245, 255);
    imagefill($image, 0, 0, $blue);
    imagefilledellipse($image, 120, 80, 100, 100, $white);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);
    $stream = new \Laminas\Diactoros\Stream('php://temp', 'w+b');
    $stream->write($bytes);
    $stream->rewind();
    $upload = new \Laminas\Diactoros\UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, '共享表情示例.png');
    $asset = $container->make(\Local\MediaLibrary\Library::class)->upload($user, $upload, ['public', 'emoji', '示例']);
    file_put_contents($statePath, json_encode(['user' => $user->id, 'uuid' => $asset->uuid]));
    echo "Disposable local browser fixture prepared.\n";
} elseif ($action === 'search') {
    if (!is_file($statePath)) {
        throw new RuntimeException('Prepare the disposable fixture first.');
    }
    $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $user = \Flarum\User\User::whereKey($state['user'])->where('username', 'media-library-ui')->firstOrFail();
    if (\Local\MediaLibrary\Asset::where('user_id', $user->id)->count() !== 1) {
        throw new RuntimeException('Search fixture requires a fresh prepare.');
    }
    $library = $container->make(\Local\MediaLibrary\Library::class);
    $asset = \Local\MediaLibrary\Asset::where('user_id', $user->id)->where('uuid', $state['uuid'])->firstOrFail();
    $library->update($user, $asset->uuid, ['version' => $asset->version, 'tags' => ['public', 'emoji', '目录验收', '示例', '旅行', '日常', '收藏', '摄影']]);
    $bytes = file_get_contents($storage->path($asset->uuid));
    for ($index = 1; $index <= 26; $index++) {
        $stream = new \Laminas\Diactoros\Stream('php://temp', 'w+b');
        $stream->write($bytes);
        $stream->rewind();
        $upload = new \Laminas\Diactoros\UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, sprintf('分页示例-%02d.png', $index));
        $library->upload($user, $upload, ['public', '目录验收', '示例', '旅行', '日常', '收藏', '摄影']);
    }
    echo "Disposable search fixture: 27 shared images with multiple tags.\n";
} elseif ($action === 'http') {
    if (!is_file($statePath)) {
        throw new RuntimeException('Prepare the disposable fixture first.');
    }
    $curl = curl_init();
    $csrf = '';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$csrf): int {
            if (stripos($line, 'X-CSRF-Token:') === 0) {
                $csrf = trim(substr($line, strlen('X-CSRF-Token:')));
            }
            return strlen($line);
        },
    ]);
    curl_setopt($curl, CURLOPT_URL, $config['url'].'/');
    if (curl_exec($curl) === false || $csrf === '') {
        throw new RuntimeException('Local HTTP session initialization failed.');
    }
    curl_setopt_array($curl, [
        CURLOPT_URL => $config['url'].'/login',
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: '.$csrf],
        CURLOPT_POSTFIELDS => json_encode(['identification' => 'media-library-ui', 'password' => 'Fixture-only-media-123']),
    ]);
    if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
        throw new RuntimeException('Local fixture sign-in failed.');
    }
    $uploadPath = __DIR__.'/../../.local/http-upload.txt';
    file_put_contents($uploadPath, str_repeat('attachment upload test ', 150000));
    try {
        curl_setopt_array($curl, [
            CURLOPT_URL => $config['url'].'/api/media-library/assets',
            CURLOPT_HTTPHEADER => ['X-CSRF-Token: '.$csrf],
            CURLOPT_POSTFIELDS => ['file' => new CURLFile($uploadPath, 'text/plain', 'http-upload.txt'), 'tags' => '["HTTP验证"]'],
        ]);
        $body = curl_exec($curl);
        if ($body === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 201) {
            throw new RuntimeException('Real multipart upload failed; HTTP '.curl_getinfo($curl, CURLINFO_RESPONSE_CODE));
        }
        echo 'Real HTTP multipart upload: '.filesize($uploadPath)." bytes, HTTP 201.\n";
    } finally {
        unlink($uploadPath);
        curl_close($curl);
    }
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) {
        exit(0);
    }
    $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $user = \Flarum\User\User::whereKey($state['user'])->where('username', 'media-library-ui')->firstOrFail();
    $assets = \Local\MediaLibrary\Asset::where('user_id', $user->id)->get();
    foreach ($assets as $asset) {
        if ($asset->posts()->exists()) {
            throw new RuntimeException('Fixture has post references; remove the disposable post first.');
        }
    }
    foreach ($assets as $asset) {
        $storage->remove($asset->uuid);
        $asset->delete();
    }
    $db->table('access_tokens')->where('user_id', $user->id)->delete();
    $db->table('users')->where('id', $user->id)->delete();
    unlink($statePath);
    echo "Disposable fixture account and files removed.\n";
} else {
    throw new RuntimeException('Use prepare, search, http or cleanup.');
}
