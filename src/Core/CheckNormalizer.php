<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Doprowadzenie listy checków do kontraktu i agregacja statusu. Normalizujemy
 * u siebie dokładnie tak, jak zrobi to hub (WpHealthClient::sanitizeChecks):
 * dzięki temu to, co widać w panelu sklepu, jest tym, co zobaczy panel Calmfox,
 * a rozszerzenie klienta nie ma jak przemycić pola spoza kontraktu.
 */
final class CheckNormalizer
{
    public const STATUSES = [CheckResult::OK, CheckResult::WARN, CheckResult::FAIL];

    private const MAX_CHECKS = 60;
    private const MAX_LABEL = 80;
    private const MAX_DETAIL = 300;
    private const MAX_FIX = 200;

    /** Limit przykładowego polecenia naprawczego — taki sam po stronie huba. */
    public const MAX_COMMAND = 200;

    /**
     * @param iterable<CheckResult|array<string, mixed>|mixed> $checks
     *
     * @return list<array{id: string, status: string, label: ?string, detail: ?string, fix: ?string, command: ?string, ms: ?int}>
     */
    public static function normalize(iterable $checks): array
    {
        $out = [];
        foreach ($checks as $check) {
            if ($check instanceof CheckResult) {
                $check = $check->toArray();
            }
            if (!\is_array($check)) {
                continue;
            }

            $id = mb_strtolower(trim((string) ($check['id'] ?? '')));
            $status = $check['status'] ?? null;
            if (1 !== preg_match('/^[a-z0-9_-]{1,40}$/', $id) || !\in_array($status, self::STATUSES, true)) {
                continue;
            }

            $ms = $check['ms'] ?? null;
            $out[] = [
                'id' => $id,
                'status' => $status,
                'label' => self::text($check['label'] ?? null, self::MAX_LABEL),
                'detail' => self::text($check['detail'] ?? null, self::MAX_DETAIL),
                'fix' => self::text($check['fix'] ?? null, self::MAX_FIX),
                'command' => self::command($check['command'] ?? null),
                'ms' => (\is_int($ms) && $ms >= 0) ? $ms : null,
            ];

            if (\count($out) >= self::MAX_CHECKS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Agregat sekcji: fail bije warn, warn bije ok. Pusta lista to `ok`:
     * brak checków nie jest awarią, tylko brakiem czego sprawdzać.
     *
     * @param iterable<CheckResult|array<string, mixed>> $checks
     */
    public static function aggregate(iterable $checks): string
    {
        $status = CheckResult::OK;
        foreach ($checks as $check) {
            $value = $check instanceof CheckResult ? $check->status : (string) ($check['status'] ?? '');
            if (CheckResult::FAIL === $value) {
                return CheckResult::FAIL;
            }
            if (CheckResult::WARN === $value) {
                $status = CheckResult::WARN;
            }
        }

        return $status;
    }

    /**
     * Przykładowe polecenie naprawcze. Panel daje przy nim przycisk kopiowania,
     * więc jest jedyną wartością z tej instalacji, którą ktoś wkleja sobie do
     * terminala: jedna linia i wyłącznie drukowalne ASCII. Za długiego NIE
     * przycinamy, tylko wyrzucamy w całości — polecenie urwane w połowie ścieżki
     * wygląda na gotowe do wklejenia, a zrobi co innego, niż mówi opis.
     */
    public static function command(mixed $value): ?string
    {
        $clean = self::text($value, self::MAX_COMMAND + 1);

        return null !== $clean && mb_strlen($clean) <= self::MAX_COMMAND
            && 1 === preg_match('/^[\x20-\x7E]+$/', $clean) ? $clean : null;
    }

    public static function text(mixed $value, int $max): ?string
    {
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $value)));

        return '' === $clean ? null : mb_substr($clean, 0, $max);
    }
}
