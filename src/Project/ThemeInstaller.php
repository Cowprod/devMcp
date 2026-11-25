<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;

use RuntimeException;

/** Installs from an already trusted, locally built distribution. Never executes theme code. */
final class ThemeInstaller
{
    public function install(string $source, string $destination, string $sha, array $resources, string $namespace = 'devmcp-theme'): array
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $sha)) throw new RuntimeException('Invalid SHA');
        $source = realpath($source);
        if ($source === false || !is_dir($source)) throw new RuntimeException('Missing distribution');
        if (is_link($destination) || !is_dir($destination)) throw new RuntimeException('Invalid destination root');
        $destination = realpath($destination);
        if (!preg_match('/^devmcp-(theme|component-[a-z0-9-]+)$/D', $namespace)) throw new RuntimeException('Invalid namespace');
        $root = $destination . '/' . $namespace;
        if (is_link($root) || (!is_dir($root) && !mkdir($root, 0755))) throw new RuntimeException('Invalid managed directory');
        if (is_link($root . '/.lock')) throw new RuntimeException('Unsafe lock');
        $lock = fopen($root . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock theme directory');
        $stage = null;
        try {
            $files = [];
            foreach ($resources as $path) {
                if (!is_string($path) || !preg_match('~^[a-zA-Z0-9_-][a-zA-Z0-9_./-]*$~D', $path) || in_array('..', explode('/', $path), true)) throw new RuntimeException('Invalid resource path');
                if (!preg_match('~\.(css|js|woff2?|ttf|otf|eot|png|jpe?g|webp|ico)$~iD', $path) || preg_match('~\.(php[0-9]*|phtml|phar|cgi|pl|asp[x]?)(\.|/)~i', $path)) throw new RuntimeException('Unsupported asset type');
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

    /**
     * Activate a managed Bootstrap link without modifying unrelated page content.
     * current.css remains a stable entrypoint; HTML is never rewritten.
     * The returned rollback callable is used if the surrounding SQL transaction fails.
     */
    public function activate(string $destination, string $sha): array
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $sha)) throw new RuntimeException('Invalid SHA');
        if (is_link($destination) || !is_dir($destination)) throw new RuntimeException('Invalid destination root');
        $destination = realpath($destination);
        $root = $destination . '/devmcp-theme';
        $css = $root . '/' . $sha . '/bootstrap.min.css';
        if (is_link($css) || !is_file($css)) throw new RuntimeException('Missing installed Bootstrap CSS');
        $index = $destination . '/index.html';
        if (!file_exists($index)) return ['page_linked'=>false,'html_changed'=>false,'css_changed'=>false];
        if (is_link($index) || !is_file($index)) throw new RuntimeException('Unsafe HTML entrypoint');
        $original = file_get_contents($index);
        if ($original === false) throw new RuntimeException('Cannot read HTML entrypoint');
        $pattern = '~<link\b[^>]*\bhref\s*=\s*(["\x27])devmcp-theme/current\.css(?:\?v=[a-f0-9]{40})?\1~i';
        if (!preg_match($pattern, $original)) return ['page_linked'=>false,'html_changed'=>false,'css_changed'=>false];
        $entrypoint = $root . '/current.css';
        if (is_link($entrypoint) || (file_exists($entrypoint) && !is_file($entrypoint)))
            throw new RuntimeException('Unsafe CSS entrypoint');
        $previousCss = is_file($entrypoint) ? file_get_contents($entrypoint) : null;
        if ($previousCss === false) throw new RuntimeException('Cannot read CSS entrypoint');
        $nextCss = '@import url("./' . $sha . '/bootstrap.min.css");' . "\n";
        $cssChanged = $previousCss !== $nextCss;
        $htmlChanged = false;
        try {
            if ($cssChanged) $this->writeManagedFile($entrypoint, $nextCss);
        } catch (\Throwable $e) {
            if ($cssChanged) {
                if ($previousCss === null) @unlink($entrypoint);
                else $this->writeManagedFile($entrypoint, $previousCss);
            }
            throw $e;
        }
        $rollback = function () use ($entrypoint, $previousCss, $cssChanged): void {
            if ($cssChanged) {
                if ($previousCss === null) {
                    if (is_link($entrypoint) || (file_exists($entrypoint) && !is_file($entrypoint)))
                        throw new RuntimeException('Unsafe CSS rollback entrypoint');
                    if (is_file($entrypoint) && !unlink($entrypoint)) throw new RuntimeException('Cannot restore CSS entrypoint');
                } else $this->writeManagedFile($entrypoint, $previousCss);
            }
        };
        return [
            'page_linked'=>true, 'html_changed'=>$htmlChanged, 'css_changed'=>$cssChanged,
            'entrypoint'=>'devmcp-theme/current.css', 'rollback'=>$rollback,
        ];
    }

    private function writeManagedFile(string $path, string $content): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('Unsafe managed file');
        $tmp = dirname($path) . '/.theme-write-' . bin2hex(random_bytes(12));
        try {
            if (file_put_contents($tmp, $content, LOCK_EX) !== strlen($content))
                throw new RuntimeException('Cannot stage managed file');
            if (is_file($path) && !chmod($tmp, fileperms($path) & 0777))
                throw new RuntimeException('Cannot preserve file permissions');
            if (!rename($tmp, $path)) throw new RuntimeException('Cannot publish managed file');
        } finally {
            if (is_file($tmp)) unlink($tmp);
        }
    }
}
