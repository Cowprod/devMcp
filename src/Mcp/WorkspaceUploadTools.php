<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Mcp;

use Cowprod\DevMcp\Project\ProjectRegistry;
use Mcp\Exception\ToolCallException;
use RuntimeException;

final class WorkspaceUploadTools {
    public function __construct(private readonly ProjectRegistry $projects) {}

    public function transfer(string $project, string $operation, ?string $upload_id = null, ?string $path = null, ?string $mode = null, ?int $size = null, ?string $sha256 = null, ?string $data = null, bool $overwrite = false, ?string $commitMode = null): array {
        $root = $this->projects->get($project)->root;
        $base = $root . '/.devmcp-uploads';
        if (is_link($base)) throw new RuntimeException('Unsafe upload directory');
        if (!is_dir($base) && !mkdir($base, 0700) && !is_dir($base)) throw new RuntimeException('Upload directory unavailable');
        if (realpath($base) !== $base) throw new RuntimeException('Unsafe upload directory');
        if ($operation === 'begin') {
            $this->cleanupExpired($base);
            if (!in_array($mode, ['temporary', 'persistent'], true) || !is_int($size) || $size < 0 || $size > 20971520 || !is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) throw new RuntimeException('Invalid upload metadata');
            if ($mode === 'persistent' && !$this->validPath($path)) throw new RuntimeException('Invalid destination');
            $id = bin2hex(random_bytes(16));
            $meta = ['path'=>$path,'mode'=>$mode,'size'=>$size,'sha256'=>$sha256,'offset'=>0,'overwrite'=>$overwrite,'created'=>time()];
            if (file_put_contents("$base/$id.json", json_encode($meta, JSON_THROW_ON_ERROR), LOCK_EX) === false || file_put_contents("$base/$id.part", '', LOCK_EX) === false) throw new RuntimeException('Upload initialization failed');
            return ['upload_id'=>$id,'max_chunk_bytes'=>49152,'next_offset'=>0];
        }
        if (!is_string($upload_id) || !preg_match('/^[a-f0-9]{32}$/D', $upload_id)) throw new RuntimeException('Invalid upload ID');
        $metaPath = "$base/$upload_id.json"; $part = "$base/$upload_id.part";
        if (is_link($metaPath) || is_link($part) || !is_file($metaPath) || !is_file($part)) throw new RuntimeException('Unknown upload');
        $handle = fopen($metaPath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Upload locked');
        try {
            $meta = json_decode(stream_get_contents($handle) ?: '', true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($meta) || time() - ($meta['created'] ?? 0) > 3600) throw new RuntimeException('Upload expired');
            if ($operation === 'status') return ['upload_id'=>$upload_id,'mode'=>$meta['mode'],'size'=>$meta['size'],'next_offset'=>$meta['offset'],'expires_at'=>$meta['created'] + 3600];
            if ($operation === 'abort') {
                unlink($part); unlink($metaPath); return ['aborted'=>true];
            }
            if ($operation === 'chunk') {
                if (!is_string($data) || $data === '' || strlen($data) > 65536 || !preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D', $data)) throw new RuntimeException('Invalid base64 chunk');
                $bytes = base64_decode($data, true);
                if ($bytes === false || strlen($bytes) > 49152 || ($meta['offset'] + strlen($bytes)) > $meta['size']) throw new RuntimeException('Chunk exceeds upload limit');
                if (filesize($part) !== $meta['offset']) throw new RuntimeException('Upload offset mismatch');
                if (file_put_contents($part, $bytes, FILE_APPEND | LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Chunk write failed');
                $meta['offset'] += strlen($bytes);
                ftruncate($handle, 0); rewind($handle); fwrite($handle, json_encode($meta, JSON_THROW_ON_ERROR)); fflush($handle);
                return ['next_offset'=>$meta['offset'],'size'=>$meta['size']];
            }
            if ($operation !== 'commit') throw new RuntimeException('Unknown upload operation');
            if ($commitMode !== null && $meta['mode'] !== $commitMode) {
                throw new ToolCallException('Use the commit tool for the initialized upload mode');
            }
            if (filesize($part) !== $meta['size'] || hash_file('sha256', $part) !== $meta['sha256']) throw new ToolCallException('Size or SHA256 mismatch');
            if ($meta['mode'] === 'temporary') {
                $target = "$base/$upload_id.ready";
            } else {
                if (!$this->validPath($meta['path'])) throw new RuntimeException('Invalid destination');
                $target = $root . '/' . $meta['path'];
                $parent = dirname($target);
                if (($resolvedParent = realpath($parent)) === false || ($resolvedParent !== $root && !str_starts_with($resolvedParent . '/', $root . '/')) || $resolvedParent !== $parent) throw new RuntimeException('Destination directory must exist within workspace');
                if (is_link($target) || (file_exists($target) && (!$meta['overwrite'] || !is_file($target)))) throw new ToolCallException('Destination exists or is unsafe');
            }
            if ($meta['mode'] === 'persistent' && !$meta['overwrite']) {
                // link is atomic and fails if another writer creates the destination first.
                if (!@link($part, $target)) throw new ToolCallException('Destination exists or publication failed');
                unlink($part);
            } elseif (!rename($part, $target)) {
                throw new RuntimeException('Publication failed');
            }
            unlink($metaPath);
            return ['committed'=>true,'mode'=>$meta['mode'],'path'=>$meta['mode'] === 'temporary' ? '.devmcp-uploads/' . $upload_id . '.ready' : $meta['path'],'sha256'=>$meta['sha256']];
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    private function cleanupExpired(string $base): void {
        $now = time();
        foreach (glob($base . '/*.json') ?: [] as $metaPath) {
            if (is_link($metaPath) || !is_file($metaPath)) continue;
            $id = basename($metaPath, '.json');
            if (!preg_match('/^[a-f0-9]{32}$/D', $id)) continue;
            $handle = fopen($metaPath, 'c+');
            if (!is_resource($handle)) continue;
            if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); continue; }
            try {
                $meta = json_decode(stream_get_contents($handle) ?: '', true);
                if (!is_array($meta) || $now - (int) ($meta['created'] ?? 0) <= 3600) continue;
                $part = $base . '/' . $id . '.part';
                if (is_file($part) && !is_link($part)) unlink($part);
                unlink($metaPath);
            } finally { flock($handle, LOCK_UN); fclose($handle); }
        }
        foreach (glob($base . '/*.ready') ?: [] as $ready) {
            if (is_link($ready) || !is_file($ready) || !preg_match('/^[a-f0-9]{32}\\.ready$/D', basename($ready))) continue;
            if ($now - filemtime($ready) > 86400) unlink($ready);
        }
    }
    private function validPath(?string $path): bool {
        return is_string($path) && strlen($path) <= 240 && preg_match('~^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[a-zA-Z0-9_./-]+$~D', $path) === 1 && !str_contains($path, '//') && !str_starts_with($path, '.devmcp-uploads/') && !str_starts_with($path, '.git/') && $path !== '.git' && $path !== '.devmcp-uploads' && !str_starts_with($path, './') && !str_contains($path, '/./') && !str_ends_with($path, '/.') && !str_ends_with($path, '/');
    }
}
