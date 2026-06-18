<?php

namespace Tests\Feature;

use App\Enums\RuleApplies;
use App\Models\Category;
use App\Models\Rule;
use App\Services\Rules\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): RuleEngine
    {
        return app(RuleEngine::class);
    }

    public function test_matchende_regel_setter_payee_memo_og_kategori(): void
    {
        $category = Category::factory()->create();
        Rule::factory()->create([
            'match_contains' => 'REMA',
            'set_payee' => 'Rema 1000',
            'set_memo' => 'Dagligvarer',
            'category_id' => $category->id,
        ]);

        $result = $this->engine()->apply('KORTKJØP REMA 1000 OSLO', -250);

        $this->assertSame('Rema 1000', $result->payee);
        $this->assertSame('Dagligvarer', $result->memo);
        $this->assertSame($category->id, $result->categoryId);
        $this->assertTrue($result->matched());
    }

    public function test_alle_termer_maa_finnes(): void
    {
        Rule::factory()->create(['match_contains' => 'REMA, OSLO', 'set_payee' => 'Rema Oslo']);

        $this->assertSame('Rema Oslo', $this->engine()->apply('REMA 1000 OSLO', -100)->payee);
        $this->assertFalse($this->engine()->apply('REMA 1000 BERGEN', -100)->matched());
    }

    public function test_must_not_contains_ekskluderer(): void
    {
        Rule::factory()->create([
            'match_contains' => 'KIWI',
            'match_not_contains' => 'MINIPRIS',
            'set_payee' => 'Kiwi',
        ]);

        $this->assertSame('Kiwi', $this->engine()->apply('KIWI STORO', -100)->payee);
        $this->assertFalse($this->engine()->apply('KIWI MINIPRIS', -100)->matched());
    }

    public function test_applies_to_skiller_inn_og_ut(): void
    {
        Rule::factory()->create([
            'match_contains' => 'LØNN',
            'applies_to' => RuleApplies::Inflow,
            'set_payee' => 'Arbeidsgiver',
        ]);

        $this->assertSame('Arbeidsgiver', $this->engine()->apply('LØNN JUNI', 30000)->payee);
        $this->assertFalse($this->engine()->apply('LØNN TREKK', -500)->matched());
    }

    public function test_mest_spesifikk_regel_vinner_ved_overlapp(): void
    {
        // Begge matcher, men regelen med flest inneholder-termer er mest spesifikk.
        Rule::factory()->create(['match_contains' => 'SHELL', 'set_payee' => 'Generell bensin']);
        Rule::factory()->create(['match_contains' => 'SHELL, 7-ELEVEN', 'set_payee' => 'Shell 7-Eleven']);

        $this->assertSame('Shell 7-Eleven', $this->engine()->apply('SHELL 7-ELEVEN OSLO', -300)->payee);
    }

    public function test_lengste_term_vinner_naar_antall_er_likt(): void
    {
        // Likt antall termer (1) → lengste samlede tekst er mest spesifikk.
        Rule::factory()->create(['match_contains' => 'KIWI', 'set_payee' => 'Kiwi generell']);
        Rule::factory()->create(['match_contains' => 'KIWI STORO', 'set_payee' => 'Kiwi Storo']);

        $this->assertSame('Kiwi Storo', $this->engine()->apply('KIWI STORO 123', -100)->payee);
    }

    public function test_inaktiv_regel_ignoreres(): void
    {
        Rule::factory()->create(['active' => false, 'match_contains' => 'REMA', 'set_payee' => 'Rema']);

        $this->assertFalse($this->engine()->apply('REMA 1000', -100)->matched());
    }

    public function test_ingen_match_gir_tomt_resultat(): void
    {
        Rule::factory()->create(['match_contains' => 'REMA', 'set_payee' => 'Rema']);

        $result = $this->engine()->apply('VIPPS OVERFØRING', -100);

        $this->assertFalse($result->matched());
        $this->assertNull($result->payee);
    }
}
