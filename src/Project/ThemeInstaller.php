<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;

use RuntimeException;

/** Installs from an already trusted, locally built distribution. Never executes theme code. */
final class ThemeInstaller
{
    public function install(string $source, string $destination, string $sha, array $resources): array
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $sha)) throw new RuntimeException('Invalid SHA');
        $source = realpath($source);
        if ($source === false || !is_dir($source)) throw new RuntimeException('Missing distribution');
        if (is_link($destination) || !is_dir($destination)) throw new RuntimeException('Invalid destination root');
        $destination = realpath($destination);
        $root = $destination . '/devmcp-theme';
        if (is_link($root) || (!is_dir($root) && !mkdir($root, 0755))) throw new RuntimeException('Invalid managed directory');
        $lock = fopen($root . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock theme directory');
        $stage = null;
        try {
            $files = [];
            foreach ($resources as $path) {
                if (!is_string($path) || !preg_match('~^[a-zA-Z0-9_-][a-zA-Z0-9_./-]*$~D', $path) || in_array('..', explode('/', $path), true)) throw new RuntimeException('Invalid resource path');
                $file = realpath($source . '/' . $path);
                if ($file === false || !str_starts_with($file, $source . '/') || !is_file($file)) throw new RuntimeException('Missing resource: ' . $path);
                $files[$path] = $file;
            }
            if (!$files) throw new RuntimeException('Empty distribution');
            ksort($files);
            $hashes = array_map(static fn($file) => hash_file('sha256', $file), $files);
            $manifest = json_encode(['sha'=>$sha,'files'=>$hashes], JSON_THROW_ON_ERROR);
            $target = $root . '/' . $sha;
            if (is_link($target)) throw new RuntimeException('Unsafe target');
            if (is_dir($target)) {
                if (file_get_contents($target . '/.manifest.json') !== $manifest) throw new RuntimeException('Existing SHA conflicts');
                foreach ($hashes as $path=>$hash) {
                    $file = realpath($target . '/' . $path);
                    if ($file === false || !str_starts_with($file, $target . '/') || hash_file('sha256', $file) !== $hash) throw new RuntimeException('Installed resource modified');
                }
                return ['sha'=>$sha,'changed'=>false,'directory'=>$target];
            }
            $stage = $root . '/.stage-' . bin2hex(random_bytes(12));
            if (!mkdir($stage, 0755)) throw new RuntimeException('Cannot stage theme');
            foreach ($files as $path=>$file) {
                $out = $stage . '/' . $path;
                if (!is_dir(dirname($out)) && !mkdir(dirname($out), 0755, true)) throw new RuntimeException('Cannot create resource directory');
                if (!copy($file,$out) || hash_file('sha256',$out) !== $hashes[$path]) throw new RuntimeException('Resource copy failed');
            }
            if (file_put_contents($stage . '/.manifest.json',$manifest) !== strlen($manifest) || !rename($stage,$target)) throw new RuntimeException('Cannot publish theme');
            $stage = null;
            return ['sha'=>$sha,'changed'=>true,'directory'=>$target];
        } finally {
            if ($stage !== null && is_dir($stage)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
                rmdir($stage);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
