<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Brukerstyrte innstillinger (nøkkel/verdi). Single-user-app, så ingen
 * brukertilknytning. Defaults og grenser defineres her.
 */
class AppSettings
{
    public const MANUAL_SYNC_DAYS = 'manual_sync_days';

    public const AUTO_SYNC_DAYS = 'auto_sync_days';

    public const REPORT_EMAIL = 'report_email';

    /** @var array<string, int> */
    public const DEFAULTS = [
        self::MANUAL_SYNC_DAYS => 10,
        self::AUTO_SYNC_DAYS => 5,
    ];

    /** @var array<string, int> */
    public const MAX = [
        self::MANUAL_SYNC_DAYS => 30,
        self::AUTO_SYNC_DAYS => 10,
    ];

    public static function int(string $key): int
    {
        $value = Setting::query()->where('key', $key)->value('value');

        return $value !== null ? (int) $value : (self::DEFAULTS[$key] ?? 0);
    }

    public static function string(string $key): ?string
    {
        $value = Setting::query()->where('key', $key)->value('value');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public static function set(string $key, int|string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    public static function manualSyncDays(): int
    {
        return self::int(self::MANUAL_SYNC_DAYS);
    }

    public static function autoSyncDays(): int
    {
        return self::int(self::AUTO_SYNC_DAYS);
    }

    /**
     * Mottaker av synk- og utløpsrapporter. Innstillingen vinner; faller tilbake
     * til `BANK_SYNC_REPORT_EMAIL` i config (legacy) hvis den ikke er satt.
     */
    public static function reportEmail(): ?string
    {
        return self::string(self::REPORT_EMAIL) ?? (config('gocardless.report_email') ?: null);
    }

    /**
     * @return array{manual_sync_days: int, auto_sync_days: int, report_email: ?string}
     */
    public static function all(): array
    {
        return [
            self::MANUAL_SYNC_DAYS => self::manualSyncDays(),
            self::AUTO_SYNC_DAYS => self::autoSyncDays(),
            self::REPORT_EMAIL => self::reportEmail(),
        ];
    }
}
