<?php

namespace App\Services\Bank;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Slår opp riktig BankDataProvider ut fra en leverandørnøkkel. Hver tilkobling
 * (`bank_connections.provider`) avgjør hvilken aggregator som brukes, slik at
 * flere leverandører kan være aktive samtidig.
 *
 * Providerne løses via containeren på sine konkrete klasser, slik at tester kan
 * bytte dem ut med FakeBankProvider via app()->instance().
 */
class BankProviderRegistry
{
    public const DEFAULT = GoCardlessProvider::KEY;

    /** @var array<string, class-string<BankDataProvider>> */
    private const PROVIDERS = [
        GoCardlessProvider::KEY => GoCardlessProvider::class,
        EnableBankingProvider::KEY => EnableBankingProvider::class,
    ];

    public function __construct(private readonly Container $app) {}

    public function get(string $key): BankDataProvider
    {
        $class = self::PROVIDERS[$key] ?? throw new InvalidArgumentException("Ukjent bankleverandør: {$key}");

        return $this->app->make($class);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function isValid(string $key): bool
    {
        return isset(self::PROVIDERS[$key]);
    }
}
