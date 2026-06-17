<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    public function test_returnerer_defaults(): void
    {
        config(['gocardless.report_email' => null]);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.manual_sync_days', 10)
            ->assertJsonPath('data.auto_sync_days', 5)
            ->assertJsonPath('data.report_email', null);
    }

    public function test_report_email_faller_tilbake_til_config(): void
    {
        config(['gocardless.report_email' => 'legacy@env.no']);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.report_email', 'legacy@env.no');
    }

    public function test_lagrer_og_nullstiller_report_email(): void
    {
        config(['gocardless.report_email' => 'legacy@env.no']);

        // Innstillingen vinner over config.
        $this->putJson('/api/settings', ['report_email' => 'ny@epost.no'])
            ->assertOk()
            ->assertJsonPath('data.report_email', 'ny@epost.no');
        $this->assertDatabaseHas('settings', ['key' => 'report_email', 'value' => 'ny@epost.no']);

        // Tom verdi nullstiller → faller tilbake til config.
        $this->putJson('/api/settings', ['report_email' => null])
            ->assertOk()
            ->assertJsonPath('data.report_email', 'legacy@env.no');
    }

    public function test_avviser_ugyldig_report_email(): void
    {
        $this->putJson('/api/settings', ['report_email' => 'ikke-en-epost'])->assertStatus(422);
    }

    public function test_oppdaterer_innstillinger(): void
    {
        $this->putJson('/api/settings', ['manual_sync_days' => 20, 'auto_sync_days' => 7])
            ->assertOk()
            ->assertJsonPath('data.manual_sync_days', 20)
            ->assertJsonPath('data.auto_sync_days', 7);

        $this->assertDatabaseHas('settings', ['key' => 'manual_sync_days', 'value' => '20']);
    }

    public function test_haandhever_maksgrenser(): void
    {
        $this->putJson('/api/settings', ['manual_sync_days' => 31])->assertStatus(422);
        $this->putJson('/api/settings', ['auto_sync_days' => 11])->assertStatus(422);
    }
}
