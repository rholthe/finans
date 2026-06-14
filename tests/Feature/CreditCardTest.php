<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Kredittkort håndteres som en vanlig budsjettkonto som kan ha negativ saldo –
 * ingen egen betalingskategori. Et kjøp oppfører seg som ethvert kategorisert
 * forbruk; kortet betales ned med en overføring (se TransferTest).
 */
class CreditCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    public function test_kredittkort_lager_ingen_betalingskategori(): void
    {
        $this->postJson('/api/accounts', [
            'name' => 'Visa',
            'type' => 'credit',
            'on_budget' => true,
        ])->assertCreated();

        $this->assertDatabaseMissing('category_groups', ['name' => 'Kredittkortbetalinger']);
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_kredittkjop_oppforer_seg_som_vanlig_forbruk(): void
    {
        $mat = Category::factory()->create();
        $checking = Account::factory()->create(['type' => 'bank', 'on_budget' => true]);
        $checking->transactions()->create(['date' => '2026-01-01', 'amount' => 5000, 'is_starting_balance' => true]);
        $this->putJson("/api/budget/2026-01/categories/{$mat->id}", ['assigned' => 1000]);

        $visa = Account::factory()->credit()->create();
        $visa->transactions()->create(['category_id' => $mat->id, 'date' => '2026-01-15', 'amount' => -300]);

        $matPayload = collect($this->getJson('/api/budget?month=2026-01')->json('groups'))
            ->flatMap(fn (array $group): array => $group['categories'])
            ->firstWhere('id', $mat->id);

        // Forbruket trekkes fra kategoriens available, ikke fra RTA (kjøpet er kategorisert).
        $this->assertEquals(700, $matPayload['available']);
        $this->getJson('/api/budget?month=2026-01')->assertJsonPath('ready_to_assign', 4000);

        // Kortet har negativ saldo (gjeld), som en hvilken som helst konto.
        $this->assertEquals(-300, $visa->transactions()->sum('amount'));
    }
}
