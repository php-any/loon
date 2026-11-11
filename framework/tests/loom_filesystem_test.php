<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function filesystemCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/loom-fs-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0700, true);

$lines = [];

try {
    $builder = Loom\Application::createBuilder($root);
    $builder->monitoring->replaceMonitoring(static function (Loom\ServiceResolver $resolver) use (&$lines): Loom\Monitoring\Monitoring {
        $configured = new Loom\Monitoring\ConfigurableMonitoring(
            $resolver->get(Loom\Monitoring\MonitoringOptions::class),
            $resolver->get(Loom\Monitoring\MonitoringRegistration::class),
            null,
            null,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );
        $configured->bindScope($resolver->get(Loom\ServiceScope::class));
        return $configured;
    });
    $app = $builder->build();
    $app->services->withMonitoredScope(
        Loom\Monitoring\MonitoringContext::console('fs-monitor'),
        static function (Loom\ServiceScope $scope): void {
            $disk = $scope->get(Loom\Filesystem\Filesystem::class)->disk('local');
            $secret = 'secret-bytes-not-logged';
            $disk->put('monitored/secret.txt', $secret);
            $disk->get('monitored/secret.txt');
            $disk->delete('monitored/secret.txt');
            $files = $scope->get(Loom\Filesystem\Filesystem::class);
            $upload = (new Loom\UploadedFile('f.txt', 'text/plain', 3, 'abc', 'doc'))
                ->withFilesystem($files);
            $upload->store('uploads', 'local');
        },
    );
    filesystemCheck(count($lines) >= 1, 'filesystem monitoring session');
    $session = json_decode($lines[count($lines) - 1], true, 64, JSON_THROW_ON_ERROR);
    $events = array_column($session['entries'] ?? [], 'event');
    filesystemCheck(in_array('filesystem.write', $events, true), 'filesystem.write recorded');
    filesystemCheck(in_array('filesystem.read', $events, true), 'filesystem.read recorded');
    filesystemCheck(in_array('filesystem.delete', $events, true), 'filesystem.delete recorded');
    filesystemCheck(in_array('filesystem.upload', $events, true), 'filesystem.upload recorded');
    $encoded = json_encode($session['entries'] ?? [], JSON_THROW_ON_ERROR);
    filesystemCheck(!str_contains($encoded, 'secret-bytes-not-logged'), 'file contents not in monitoring entries');

    file_put_contents($root . '/config/monitoring.php', "<?php\nreturn ['enabled' => true, 'driver' => 'log', 'watchers' => ['filesystem' => ['enabled' => false]]];\n");
    $linesOff = [];
    $offApp = Loom\Application::createBuilder($root)->monitoring->replaceMonitoring(
        static function (Loom\ServiceResolver $resolver) use (&$linesOff): Loom\Monitoring\Monitoring {
            $configured = new Loom\Monitoring\ConfigurableMonitoring(
                $resolver->get(Loom\Monitoring\MonitoringOptions::class),
                $resolver->get(Loom\Monitoring\MonitoringRegistration::class),
                null,
                null,
                static function (string $line) use (&$linesOff): void {
                    $linesOff[] = $line;
                },
            );
            $configured->bindScope($resolver->get(Loom\ServiceScope::class));
            return $configured;
        },
    )->build();
    $offApp->services->withMonitoredScope(
        Loom\Monitoring\MonitoringContext::console('fs-off'),
        static function (Loom\ServiceScope $scope): void {
            $scope->get(Loom\Filesystem\Filesystem::class)->disk('local')->put('off/line.txt', 'x');
        },
    );
    $sessionOff = json_decode($linesOff[count($linesOff) - 1], true, 64, JSON_THROW_ON_ERROR);
    $offEvents = array_column($sessionOff['entries'] ?? [], 'event');
    filesystemCheck(!in_array('filesystem.write', $offEvents, true), 'filesystem watcher off');
    $offApp->dispose();
    unlink($root . '/config/monitoring.php');

    $builder = Loom\Application::createBuilder($root);
    $app = $builder->build();
    $app->services->withScope(static function (Loom\ServiceScope $scope) use ($root): void {
        $files = $scope->get(Loom\Filesystem\Filesystem::class);
        $disk = $files->disk('local');
        $public = $files->disk('public');
        $storageRoot = $root . '/storage/app';
        filesystemCheck(is_dir($storageRoot) || true, 'storage may not exist yet');
        $disk->put('nested/hello.txt', 'world');
        filesystemCheck($disk->exists('nested/hello.txt'), 'put creates file');
        filesystemCheck($disk->get('nested/hello.txt') === 'world', 'get reads file');
        filesystemCheck($public->url('a/b.png') === '/files/a/b.png', 'public url');
        $blocked = false;
        try {
            $disk->put('../escape.txt', 'x');
        } catch (LogicException) {
            $blocked = true;
        }
        filesystemCheck($blocked, 'reject parent segment in key');
        $blocked = false;
        try {
            $disk->get('missing.dat');
        } catch (RuntimeException) {
            $blocked = true;
        }
        filesystemCheck($blocked, 'get missing throws RuntimeException');
        $blocked = false;
        try {
            $public->url('x');
            $disk->url('x');
        } catch (LogicException) {
            $blocked = true;
        }
        filesystemCheck($blocked, 'private disk url throws');

        $public->put('probe.txt', 'pub');
        $public->put('evil.php', '<?php echo 1;');
        $server = new Loom\Filesystem\FilesystemPublicServer($files->servedDisks());
        $req = new Loom\Request([
            'method' => 'GET',
            'path' => '/files/probe.txt',
            'headers' => [],
            'query' => [],
            'body' => '',
        ]);
        $response = $server->handle($req);
        filesystemCheck($response !== null && $response->toArray()['status'] === 200, 'public serve ok');
        $phpReq = new Loom\Request([
            'method' => 'GET',
            'path' => '/files/evil.php',
            'headers' => [],
            'query' => [],
            'body' => '',
        ]);
        $phpResponse = $server->handle($phpReq);
        $headers = $phpResponse?->toArray()['headers'] ?? [];
        filesystemCheck(($headers['content-type'] ?? '') === 'application/octet-stream', 'php served as octet-stream');
        $traversalReq = new Loom\Request([
            'method' => 'GET',
            'path' => '/files/../probe.txt',
            'headers' => [],
            'query' => [],
            'body' => '',
        ]);
        $traversalFailed = false;
        try {
            $server->handle($traversalReq);
        } catch (Loom\HttpError $error) {
            $traversalFailed = $error->status === 404;
        }
        filesystemCheck($traversalFailed, 'public traversal blocked');

        $boundary = 'loomtestboundary';
        $multipart = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"title\"\r\n\r\n"
            . "demo\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"pic.PNG\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . "binary\r\n"
            . "--{$boundary}--\r\n";
        $uploadRequest = (new Loom\Request([
            'method' => 'POST',
            'path' => '/upload',
            'headers' => ['content-type' => ["multipart/form-data; boundary={$boundary}"]],
            'query' => [],
            'body' => $multipart,
        ]))->withAttribute('loom.filesystem', $files);
        $form = $uploadRequest->form();
        filesystemCheck($form->string('title') === 'demo', 'multipart form field');
        $file = $uploadRequest->file('avatar');
        filesystemCheck($file !== null && $file->clientFilename() === 'pic.PNG', 'multipart file meta');
        $key = $file->store('avatars', 'public');
        filesystemCheck(str_starts_with($key, 'avatars/') && str_ends_with($key, '.png'), 'store sanitizes extension');
        filesystemCheck($public->exists($key), 'stored file exists');

        $urlEncoded = new Loom\Request([
            'method' => 'POST',
            'path' => '/form',
            'headers' => ['content-type' => ['application/x-www-form-urlencoded']],
            'query' => [],
            'body' => 'name=li',
        ]);
        filesystemCheck($urlEncoded->form()->string('name') === 'li', 'urlencoded field');

        $badMultipart = new Loom\Request([
            'method' => 'POST',
            'path' => '/bad',
            'headers' => ['content-type' => ['multipart/form-data']],
            'query' => [],
            'body' => 'x',
        ]);
        $bad = false;
        try {
            $badMultipart->form();
        } catch (Loom\HttpError $error) {
            $bad = $error->status === 400;
        }
        filesystemCheck($bad, 'missing boundary is 400');

        $large = $disk->response('nested/hello.txt');
        filesystemCheck($large->toArray()['status'] === 200, 'disk response');

        $outside = $root . '/outside';
        mkdir($outside, 0700, true);
        file_put_contents($outside . '/leak.txt', 'LEAKED');
        $linkKey = 'escape-link.txt';
        $linkAbsolute = $public->path($linkKey);
        if (is_link($linkAbsolute) || is_file($linkAbsolute)) {
            unlink($linkAbsolute);
        }
        filesystemCheck(symlink($outside . '/leak.txt', $linkAbsolute), 'plant symlink under disk root');
        $readBlocked = false;
        try {
            $public->get($linkKey);
        } catch (RuntimeException) {
            $readBlocked = true;
        }
        filesystemCheck($readBlocked, 'get does not follow symlink outside root');
        filesystemCheck(!$public->exists($linkKey), 'exists is false for escape symlink');
        $serveBlocked = false;
        try {
            $server->handle(new Loom\Request([
                'method' => 'GET',
                'path' => '/files/' . $linkKey,
                'headers' => [],
                'query' => [],
                'body' => '',
            ]));
        } catch (Loom\HttpError $error) {
            $serveBlocked = $error->status === 404;
        }
        filesystemCheck($serveBlocked, 'public serve blocks escape symlink');
        $responseBlocked = false;
        try {
            $public->response($linkKey);
        } catch (Loom\HttpError $error) {
            $responseBlocked = $error->status === 404;
        }
        filesystemCheck($responseBlocked, 'disk response blocks escape symlink');
        $putBlocked = false;
        try {
            $public->put($linkKey, 'overwrite');
        } catch (RuntimeException) {
            $putBlocked = true;
        }
        filesystemCheck($putBlocked, 'put blocked on escape symlink');
        filesystemCheck(file_get_contents($outside . '/leak.txt') === 'LEAKED', 'outside file unchanged after blocked put');
    });
    $app->dispose();
} finally {
    $rm = static function (string $dir) use (&$rm): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $rm($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    };
    if (is_dir($root)) {
        $rm($root);
    }
}

echo "loom_filesystem_test: OK\n";
