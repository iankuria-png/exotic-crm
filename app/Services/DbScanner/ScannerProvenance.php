<?php

namespace App\Services\DbScanner;

/** Captured in the executing worker, not inferred from a later deployment. */
class ScannerProvenance
{
    public static function code(): array
    {
        static $proof;
        if ($proof !== null) {
            return $proof;
        }
        $git = base_path('.git');
        $head = trim((string) @file_get_contents($git.'/HEAD'));
        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $head = trim((string) @file_get_contents($git.'/'.$ref));
            if ($head === '') {
                foreach (explode("\n", (string) @file_get_contents($git.'/packed-refs')) as $line) {
                    if (str_ends_with($line, ' '.$ref)) {
                        $head = explode(' ', $line)[0];
                    }
                }
            }
        }
        $paths = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Services/DbScanner'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);
        $hash = hash_init('sha256');
        foreach ($paths as $path) {
            hash_update($hash, substr($path, strlen(base_path())).':'.hash_file('sha256', $path));
        }

        return $proof = ['commit' => preg_match('/^[0-9a-f]{40}$/', $head) ? $head : (config('db_scanner.code_commit') ?: 'unknown'), 'source_hash' => hash_final($hash)];
    }

    public static function packs(array $rules): string
    {
        $versions = [];
        foreach ($rules as $key => $rule) {
            $versions[$key] = $rule['version_hash'] ?? $rule['definition_hash'] ?? hash('sha256', json_encode($rule));
        }
        ksort($versions);

        return hash('sha256', json_encode($versions));
    }
}
