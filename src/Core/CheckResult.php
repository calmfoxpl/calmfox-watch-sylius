<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Pojedyncze sprawdzenie w kontrakcie wtyczek: ok / warn / fail. Tylko `fail`
 * przełącza adres kontrolny na HTTP 503 i budzi monitoring, `warn` zostaje
 * w panelu i w raporcie. Identyfikator jest częścią kontraktu (katalog parametrów
 * w panelu opisuje każdy check własnym tekstem), więc nie wymyślamy nowych
 * dla rzeczy, które już mają swój identyfikator.
 */
final class CheckResult
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /**
     * `fix` mówi, co ma być ustawione (wartość docelowa, ścieżka), `command` jest
     * przykładem do skopiowania. Oba są opcjonalne i przy statusie `ok` po prostu
     * ich nie ma — przy działającym parametrze nie ma czego naprawiać.
     */
    private function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $label,
        public readonly ?string $detail,
        public readonly ?int $ms,
        public readonly ?string $fix = null,
        public readonly ?string $command = null,
    ) {
    }

    public static function ok(string $id, string $label, ?string $detail = null, ?int $ms = null): self
    {
        return new self($id, self::OK, $label, $detail, $ms);
    }

    public static function warn(string $id, string $label, ?string $detail = null, ?int $ms = null, ?string $fix = null, ?string $command = null): self
    {
        return new self($id, self::WARN, $label, $detail, $ms, $fix, $command);
    }

    public static function fail(string $id, string $label, ?string $detail = null, ?int $ms = null, ?string $fix = null, ?string $command = null): self
    {
        return new self($id, self::FAIL, $label, $detail, $ms, $fix, $command);
    }

    /** Status wyliczony w checku (np. z progów), a nie wybrany wprost. */
    public static function of(string $status, string $id, string $label, ?string $detail = null, ?int $ms = null, ?string $fix = null, ?string $command = null): self
    {
        // Podpowiedź naprawcza przy „ok" byłaby myląca: parametr działa, nie ma
        // czego naprawiać. Odcinamy ją tu, a nie w każdym checku z osobna.
        $broken = self::OK !== $status;

        return new self($id, $status, $label, $detail, $ms, $broken ? $fix : null, $broken ? $command : null);
    }

    /** @return array{id: string, status: string, label: string, detail: ?string, fix: ?string, command: ?string, ms: ?int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'label' => $this->label,
            'detail' => $this->detail,
            'fix' => $this->fix,
            'command' => $this->command,
            'ms' => $this->ms,
        ];
    }
}
