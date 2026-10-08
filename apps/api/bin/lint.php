<?php

declare(strict_types=1);

// php -l over all PHP files (no Composer needed).
$root = dirname(__DIR__);
$failed = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = $file->getPathname();
    if ($file->getExtension() !== 'php' || str_contains($path, '/vendor/')) {
        continue;
    }
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        echo implode("\n", $out), "\n";
        $failed++;
    }
    $out = [];
}
echo $failed === 0 ? "PHP lint ok\n" : "{$failed} PHP files with errors\n";
exit($failed === 0 ? 0 : 1);
