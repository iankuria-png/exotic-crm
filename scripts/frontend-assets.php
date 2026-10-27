<?php

/** Public bundle validation. Never traverses symlinks or changes application/private files. */
function frontendAssets(string $root, bool $repair = false): array
{
    $root = realpath($root) ?: throw new RuntimeException('Repository root unavailable.');
    $base = $root.'/public/build';
    foreach ([$root.'/public', $base] as $dir) {
        if (is_link($dir) || realpath($dir) !== $dir) {
            throw new RuntimeException('Public build must use regular directories.');
        }
    }
    $manifestPath = $base.'/manifest.json';
    if (is_link($manifestPath) || ! is_file($manifestPath)) {
        throw new RuntimeException('Build manifest unavailable.');
    }
    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || empty($manifest['resources/js/app.jsx']['isEntry'])) {
        throw new RuntimeException('Application entry missing from build manifest.');
    }
    $files = ['manifest.json'];
    foreach ($manifest as $entry) {
        if (! is_array($entry) || ! is_string($entry['file'] ?? null)) {
            throw new RuntimeException('Invalid build entry.');
        }
        foreach (array_merge([$entry['file']], $entry['css'] ?? [], $entry['assets'] ?? []) as $file) {
            if (! is_string($file) || ! preg_match('#^assets/[A-Za-z0-9_./-]+$#D', $file)
                || in_array('..', explode('/', $file), true) || str_contains($file, '//')) {
                throw new RuntimeException('Unsafe build asset path.');
            }
            $files[] = $file;
        }
    }
    $files = array_values(array_unique($files));
    $dirs = [$base];
    // Validate the whole manifest before changing any mode.
    foreach ($files as $file) {
        $path = $base.'/'.$file;
        if (! is_file($path) || is_link($path) || realpath($path) !== $path) {
            throw new RuntimeException('Missing or linked build asset: '.$file);
        }
        for ($dir = dirname($path); $dir !== $base; $dir = dirname($dir)) {
            if (is_link($dir) || realpath($dir) !== $dir) {
                throw new RuntimeException('Linked build directory.');
            }
            $dirs[] = $dir;
        }
    }
    foreach (array_unique($dirs) as $dir) {
        if ($repair && ! chmod($dir, 0755)) {
            throw new RuntimeException('Cannot set build directory mode.');
        }
        clearstatcache(true, $dir);
        if ((fileperms($dir) & 0777) !== 0755) {
            throw new RuntimeException('Build directory must be 0755: '.$dir);
        }
    }
    $result = [];
    foreach ($files as $file) {
        $path = $base.'/'.$file;
        if ($repair && ! chmod($path, 0644)) {
            throw new RuntimeException('Cannot set build file mode.');
        }
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0644) {
            throw new RuntimeException('Public asset must be 0644: '.$file);
        }
        $result[$file] = ['sha256' => hash_file('sha256', $path), 'mode' => '0644'];
    }

    return $result;
}

/** Verify actual served bytes; an HTML shell returning200 cannot substitute for a bundle. */
function frontendAssetsWeb(string $root, string $origin): array
{
    $url = parse_url($origin);
    if (! $url || (isset($url['user']) || isset($url['pass'])) || isset($url['query']) || isset($url['fragment'])
        || ! in_array($url['scheme'] ?? '', ['https', 'http'], true) || empty($url['host'])
        || ! in_array($url['path'] ?? '', ['', '/'], true)
        || (($url['scheme'] ?? '') === 'http' && ! in_array($url['host'], ['127.0.0.1', 'localhost'], true))) {
        throw new RuntimeException('Expected public HTTPS origin.');
    }
    $origin = rtrim($origin, '/');
    $assets = frontendAssets($root);
    $get = function (string $path) use ($origin): array {
        $curl = curl_init($origin.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_MAXFILESIZE => 16777216]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: '';
        curl_close($curl);
        if (! is_string($body) || $status !== 200) {
            throw new RuntimeException('Public frontend GET failed: '.$path.' HTTP '.$status);
        }

        return [$body, $type];
    };
    [$home] = $get('/');
    $manifest = json_decode(file_get_contents($root.'/public/build/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $entry = $manifest['resources/js/app.jsx'];
    foreach (array_merge([$entry['file']], $entry['css'] ?? []) as $file) {
        if (! str_contains($home, '/build/'.$file)) {
            throw new RuntimeException('Homepage does not reference the current bundle: '.$file);
        }
    }
    $served = [];
    foreach ($assets as $file => $row) {
        if ($file === 'manifest.json') {
            continue;
        }
        [$body, $type] = $get('/build/'.$file);
        if (! hash_equals($row['sha256'], hash('sha256', $body))) {
            throw new RuntimeException('Served frontend bytes differ: '.$file);
        }
        if (str_ends_with($file, '.js') && ! preg_match('#^(?:text|application)/(?:javascript|ecmascript)(?:;|$)#i', $type)) {
            throw new RuntimeException('Incorrect JavaScript MIME: '.$file);
        }
        if (str_ends_with($file, '.css') && ! preg_match('#^text/css(?:;|$)#i', $type)) {
            throw new RuntimeException('Incorrect CSS MIME: '.$file);
        }
        $served[$file] = ['http_status' => 200, 'content_type' => $type, 'sha256' => $row['sha256']];
    }

    return $served;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $repair = in_array('--repair-modes', array_slice($argv, 1), true);
        if (array_diff(array_slice($argv, 1), ['--repair-modes', '--web'])) {
            throw new RuntimeException('Only --repair-modes supported.');
        }
        $root = getenv('DEPLOY_ASSET_ROOT_RUNTIME') ?: dirname(__DIR__);
        $result = ['verified' => true, 'assets' => frontendAssets($root, $repair)];
        if (in_array('--web', array_slice($argv, 1), true)) {
            $origin = getenv('DEPLOY_PUBLIC_ORIGIN');
            if (! $origin) {
                require $root.'/vendor/autoload.php';
                $app = require $root.'/bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                $origin = (string) config('app.url');
            }
            $result['served'] = frontendAssetsWeb($root, $origin);
        }
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage()."\n");
        exit(1);
    }
}
