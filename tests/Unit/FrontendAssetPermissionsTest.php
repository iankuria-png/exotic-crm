<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../scripts/frontend-assets.php';

class FrontendAssetPermissionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/crm-assets-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/public/build/assets', 0755, true);
        chmod($this->root.'/public/build', 0755);
        chmod($this->root.'/public/build/assets', 0755);
        $this->write('assets/app.js', 'console.log("ready");');
        $this->write('assets/app.css', 'body{color:black}');
        $this->manifest(['file' => 'assets/app.js', 'isEntry' => true, 'css' => ['assets/app.css']]);
        file_put_contents($this->root.'/.env', 'private-fixture');
        chmod($this->root.'/.env', 0600);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && ! $f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function write(string $f, string $data): void
    {
        file_put_contents($this->root.'/public/build/'.$f, $data);
        chmod($this->root.'/public/build/'.$f, 0644);
    }

    private function manifest(array $entry): void
    {
        $this->write('manifest.json', json_encode(['resources/js/app.jsx' => $entry]));
    }

    public function test_rejects_the_actual_private_mode_regression(): void
    {
        chmod($this->root.'/public/build/assets/app.js', 0600);
        $this->expectExceptionMessage('must be 0644');
        \frontendAssets($this->root);
    }

    public function test_repairs_only_manifest_runtime_files_and_keeps_private_files_private(): void
    {
        chmod($this->root.'/public/build', 0700);
        chmod($this->root.'/public/build/assets', 0700);
        chmod($this->root.'/public/build/manifest.json', 0600);
        chmod($this->root.'/public/build/assets/app.js', 0600);
        file_put_contents($this->root.'/public/build/private-note', 'private');
        chmod($this->root.'/public/build/private-note', 0600);
        $rows = \frontendAssets($this->root, true);
        $this->assertCount(3, $rows);
        $this->assertSame(hash('sha256', 'console.log("ready");'), $rows['assets/app.js']['sha256']);
        $this->assertSame(0600, fileperms($this->root.'/.env') & 0777);
        $this->assertSame(0600, fileperms($this->root.'/public/build/private-note') & 0777);
        $this->assertSame($rows, \frontendAssets($this->root));
    }

    public function test_refuses_missing_css_before_repairing_any_file(): void
    {
        chmod($this->root.'/public/build/assets/app.js', 0600);
        unlink($this->root.'/public/build/assets/app.css');
        try {
            \frontendAssets($this->root, true);
            $this->fail('Missing asset accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Missing or linked', $e->getMessage());
        }
        $this->assertSame(0600, fileperms($this->root.'/public/build/assets/app.js') & 0777);
    }

    public function test_rejects_a_link_to_a_private_file_without_changing_its_mode(): void
    {
        unlink($this->root.'/public/build/assets/app.js');
        symlink($this->root.'/.env', $this->root.'/public/build/assets/app.js');
        try {
            \frontendAssets($this->root, true);
            $this->fail('Link accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('linked', $e->getMessage());
        }
        $this->assertSame(0600, fileperms($this->root.'/.env') & 0777);
    }

    public function test_rejects_a_manifest_path_outside_the_build(): void
    {
        $this->manifest(['file' => 'assets/../../../.env', 'isEntry' => true]);
        $this->expectExceptionMessage('Unsafe');
        \frontendAssets($this->root, true);
    }

    public function test_deployment_guard_survives_an_old_checkout_and_hostile_inherited_umask(): void
    {
        $script = $this->root.'/copied-guard.php';
        copy(__DIR__.'/../../scripts/frontend-assets.php', $script);
        chmod($this->root.'/public/build/assets/app.js', 0600);
        $cmd = [PHP_BINARY, $script, '--repair-modes'];
        $old = umask(0077);
        try {
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $io, null, ['DEPLOY_ASSET_ROOT_RUNTIME' => $this->root]);
        } finally {
            umask($old);
        }
        $out = stream_get_contents($io[1]);
        $err = stream_get_contents($io[2]);
        fclose($io[1]);
        fclose($io[2]);
        $this->assertSame(0, proc_close($p), $err);
        $this->assertTrue(json_decode($out, true)['verified']);
        clearstatcache();
        $this->assertSame(0644, fileperms($this->root.'/public/build/assets/app.js') & 0777);
    }

    private function withServer(string $router, callable $test): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        file_put_contents($this->root.'/router.php', $router);
        file_put_contents($this->root.'/public/index.html', '<script src="/build/assets/app.js"></script><link rel="stylesheet" href="/build/assets/app.css">');
        $log = $this->root.'/server.log';
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $this->root.'/public', $this->root.'/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($i = 0; $i < 50; $i++) {
                $sock = @fsockopen('127.0.0.1', $port);
                if ($sock) {
                    fclose($sock);
                    $ready = true;
                    break;
                }
                usleep(10000);
            }
            $this->assertTrue($ready, 'Fixture server did not start');
            $test('http://127.0.0.1:'.$port);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function test_verifies_actual_http_bundle_bytes_and_types(): void
    {
        $this->withServer('<?php return false;', function ($origin) {
            $rows = \frontendAssetsWeb($this->root, $origin);
            $this->assertCount(2, $rows);
            $this->assertSame(200, $rows['assets/app.js']['http_status']);
        });
    }

    public function test_http_200_with_stale_bundle_bytes_is_not_a_pass(): void
    {
        $this->withServer('<?php if ($_SERVER["REQUEST_URI"] === "/build/assets/app.js") { header("Content-Type: application/javascript"); echo "stale"; return; } return false;', function ($origin) {
            $this->expectExceptionMessage('Served frontend bytes differ');
            \frontendAssetsWeb($this->root, $origin);
        });
    }

    public function test_http_200_with_wrong_javascript_mime_is_not_a_pass(): void
    {
        $this->withServer('<?php if ($_SERVER["REQUEST_URI"] === "/build/assets/app.js") { header("Content-Type: text/html"); readfile(__DIR__."/public/build/assets/app.js"); return; } return false;', function ($origin) {
            $this->expectExceptionMessage('Incorrect JavaScript MIME');
            \frontendAssetsWeb($this->root, $origin);
        });
    }

    public function test_apache_forbidden_bundle_is_not_a_pass(): void
    {
        $this->withServer('<?php if ($_SERVER["REQUEST_URI"] === "/build/assets/app.js") { http_response_code(403); echo "Forbidden"; return; } return false;', function ($origin) {
            $this->expectExceptionMessage('HTTP 403');
            \frontendAssetsWeb($this->root, $origin);
        });
    }
}
