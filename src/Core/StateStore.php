<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Stan pakietu w PLIKU, świadomie nie w bazie. Powód jest w kontrakcie:
 * przy padniętej bazie adres kontrolny MA odpowiedzieć „db: fail" i kodem 503,
 * a nie zamilknąć. To jedyny moment, w którym monitoring naprawdę zarabia,
 * więc sekret, znacznik parowania i historia nie mogą od bazy zależeć.
 *
 * Zapis jest atomowy (plik tymczasowy + rename), prawa 600, katalog 700.
 * Ostatni zapis wygrywa: równoległe żądania scalają się na świeżo odczytanym
 * stanie, więc gubimy najwyżej jeden znacznik czasu, nigdy cały plik.
 */
final class StateStore
{
    private ?array $cache = null;

    public function __construct(
        private readonly string $dir,
        private readonly string $filename = 'state.json',
    ) {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function path(): string
    {
        return rtrim($this->dir, '/').'/'.$this->filename;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }
        $raw = @file_get_contents($this->path());
        $data = false === $raw ? null : json_decode($raw, true);

        return $this->cache = \is_array($data) ? $data : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return \array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @param array<string, mixed> $changes */
    public function set(array $changes): void
    {
        $this->cache = null; // świeży odczyt: w międzyczasie mógł pisać inny proces
        $this->write(array_merge($this->all(), $changes));
    }

    public function remove(string ...$keys): void
    {
        $this->cache = null;
        $data = $this->all();
        foreach ($keys as $key) {
            unset($data[$key]);
        }
        $this->write($data);
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $this->ensureDir();

        $tmp = rtrim($this->dir, '/').'/.state-'.bin2hex(random_bytes(6)).'.tmp';
        $json = (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === @file_put_contents($tmp, $json, \LOCK_EX)) {
            throw new \RuntimeException(sprintf('Nie mogę zapisać stanu Calmfox Watch w %s. Sprawdź prawa zapisu.', $this->dir));
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path())) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Nie mogę podmienić pliku stanu %s.', $this->path()));
        }

        $this->cache = $data;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(sprintf('Nie mogę utworzyć katalogu stanu %s.', $this->dir));
        }

        // Tania polisa na wypadek, gdyby ktoś ustawił katalog stanu wewnątrz
        // public/: w pliku leży sekret adresu kontrolnego, więc serwer WWW
        // nie ma prawa go wydać.
        $guard = rtrim($this->dir, '/').'/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
    }
}
