<?php

namespace Tests\Unit;

use App\Services\Bank\BankBalance;
use Tests\TestCase;

class BankBalanceTest extends TestCase
{
    public function test_velger_bokfort_og_tilgjengelig_fra_gocardless_typer(): void
    {
        $balance = BankBalance::fromList([
            ['type' => 'closingBooked', 'amount' => 1000.0, 'currency' => 'NOK'],
            ['type' => 'interimAvailable', 'amount' => 950.0, 'currency' => 'NOK'],
        ]);

        $this->assertSame(1000.0, $balance->booked);
        $this->assertSame(950.0, $balance->available);
        $this->assertSame('NOK', $balance->currency);
    }

    public function test_velger_bokfort_og_tilgjengelig_fra_enable_banking_koder(): void
    {
        $balance = BankBalance::fromList([
            ['type' => 'CLBD', 'amount' => -500.0, 'currency' => 'NOK'], // kredittkortgjeld
            ['type' => 'ITAV', 'amount' => -540.0, 'currency' => 'NOK'],
        ]);

        $this->assertSame(-500.0, $balance->booked);
        $this->assertSame(-540.0, $balance->available);
    }

    public function test_foretrekker_interim_foran_closing_for_bokfort(): void
    {
        $balance = BankBalance::fromList([
            ['type' => 'closingBooked', 'amount' => 1000.0],
            ['type' => 'interimBooked', 'amount' => 1010.0],
        ]);

        $this->assertSame(1010.0, $balance->booked);
    }

    public function test_manglende_type_gir_null(): void
    {
        $balance = BankBalance::fromList([
            ['type' => 'closingBooked', 'amount' => 1000.0],
        ]);

        $this->assertSame(1000.0, $balance->booked);
        $this->assertNull($balance->available);
    }
}
