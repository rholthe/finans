<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    private function loan(?float $rate, float $balance): Account
    {
        $account = Account::factory()->create([
            'type' => AccountType::Loan,
            'on_budget' => false,
            'interest_rate' => $rate,
        ]);

        $account->transactions()->create([
            'date' => '2020-01-01',
            'amount' => $balance,
            'is_starting_balance' => true,
        ]);

        return $account;
    }

    public function test_konto_kan_lagre_effektiv_rente(): void
    {
        $account = Account::factory()->create(['type' => AccountType::Loan]);

        $this->putJson("/api/accounts/{$account->id}", ['interest_rate' => 5.5])
            ->assertOk()
            ->assertJsonPath('data.interest_rate', 5.5);

        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'interest_rate' => 5.5]);
    }

    public function test_renteberegning_posterer_paa_lan_og_er_idempotent(): void
    {
        $account = $this->loan(rate: 6.0, balance: -120000);

        $service = app(LoanService::class);
        $month = CarbonImmutable::parse('2026-07-01');

        $posted = $service->postMonthlyInterest($month);
        $this->assertSame(1, $posted);

        // Effektiv 6 % → månedsrente (1.06^(1/12) − 1) ≈ 0.486755 % av 120000 ≈ 584.11.
        $tx = $account->transactions()->where('external_id', 'loan-interest:2026-07')->first();
        $this->assertNotNull($tx);
        $this->assertEqualsWithDelta(-584.11, (float) $tx->amount, 0.5);
        $this->assertSame('Renter', $tx->payee);

        // Ny kjøring samme måned skal ikke dobbeltpostere.
        $this->assertSame(0, $service->postMonthlyInterest($month));
        $this->assertSame(1, $account->transactions()->where('external_id', 'loan-interest:2026-07')->count());
    }

    public function test_renteberegning_hopper_over_uten_rente_og_nedbetalte(): void
    {
        $this->loan(rate: null, balance: -50000);     // ingen rente
        $this->loan(rate: 5.0, balance: 0);           // nedbetalt

        $this->assertSame(0, app(LoanService::class)->postMonthlyInterest());
    }

    public function test_projeksjon_beregner_nedbetalingsdato(): void
    {
        // Startsaldo −22000 + 6 × 2000 innbetalinger = −10000 nåværende gjeld.
        $account = $this->loan(rate: 0.0, balance: -22000);
        $start = CarbonImmutable::now()->startOfMonth();
        for ($i = 1; $i <= 6; $i++) {
            $account->transactions()->create([
                'date' => $start->subMonths($i)->addDays(5)->toDateString(),
                'amount' => 2000,
            ]);
        }

        $projection = app(LoanService::class)->projection($account->refresh(), 6);

        // 6 × 2000 / 6 = 2000 i snitt; 10000 / 2000 = 5 mnd.
        $this->assertSame(2000.0, $projection['avg_payment']);
        $this->assertSame(5, $projection['months_to_payoff']);
        $this->assertNotNull($projection['payoff_month']);
        $this->assertSame(0.0, $projection['series'][count($projection['series']) - 1]['balance']);
    }

    public function test_projeksjon_markerer_ikke_nedbetalbar(): void
    {
        // Høy rente, ingen innbetaling → aldri nedbetalt.
        $account = $this->loan(rate: 8.0, balance: -200000);

        $projection = app(LoanService::class)->projection($account, 6);

        $this->assertSame(0.0, $projection['avg_payment']);
        $this->assertNull($projection['payoff_month']);
        $this->assertNull($projection['months_to_payoff']);
        $this->assertCount(1, $projection['series']); // bare startpunktet
    }

    public function test_projeksjon_endepunkt_avviser_ikke_lan(): void
    {
        $account = Account::factory()->create(['type' => AccountType::Bank]);

        $this->getJson("/api/accounts/{$account->id}/loan-projection")
            ->assertStatus(422);
    }

    public function test_projeksjon_endepunkt_gir_serie_for_lan(): void
    {
        $account = $this->loan(rate: 4.0, balance: -50000);

        $this->getJson("/api/accounts/{$account->id}/loan-projection?basis=3")
            ->assertOk()
            ->assertJsonPath('basis_months', 3)
            ->assertJsonPath('balance', -50000);
    }
}
