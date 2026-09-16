<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Pamięć podręczna wyników w plikach, obok stanu. Świadomie NIE korzystamy tu
 * z puli cache aplikacji: gdy padnie Redis albo baza, adres kontrolny ma dalej
 * odpowiadać i mówić, co jest zepsute. Poza tym jeden z checków sprawdza właśnie
 * pulę aplikacji, więc trzymanie w niej własnych wyników byłoby błędnym kołem.
 */
final class FileCache
{
    public function __construct(private readonly string $dir)
    {
    }

    public function get(string $key): mixed
    {
        $raw = @file_get_contents($this->path($key));
        if (false === $raw) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!\is_array($data) || !isset($data['expiresAt'])) {
            return null;
        }

        return (int) $data['expiresAt'] > time() ? ($data['value'] ?? null) : null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return; // brak miejsca na cache nie może wywrócić odpowiedzi
        }
        $tmp = rtrim($this->dir, '/').'/.cache-'.bin2hex(random_bytes(6)).'.tmp';
        $json = (string) json_encode(['expiresAt' => time() + $ttl, 'value' => $value], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === @file_put_contents($tmp, $json, \LOCK_EX)) {
            return;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path($key))) {
            @unlink($tmp);
        }
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return rtrim($this->dir, '/').'/cache-'.preg_replace('/[^a-z0-9_-]/i', '_', $key).'.json';
    }
}
