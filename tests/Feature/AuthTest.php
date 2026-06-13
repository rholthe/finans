<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Kjent passord-hash for testene, uavhengig av .env.
        config(['auth.app_password_hash' => Hash::make('test-passord')]);
    }

    public function test_rapporterer_ikke_innlogget_i_utgangspunktet(): void
    {
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJson(['authenticated' => false]);
    }

    public function test_avviser_feil_passord(): void
    {
        $this->postJson('/api/login', ['password' => 'feil'])
            ->assertStatus(422);
    }

    public function test_logger_inn_med_riktig_passord(): void
    {
        $this->postJson('/api/login', ['password' => 'test-passord'])
            ->assertOk()
            ->assertJson(['authenticated' => true]);

        $this->getJson('/api/me')->assertJson(['authenticated' => true]);
    }

    public function test_blokkerer_beskyttede_ruter_uten_innlogging(): void
    {
        $this->getJson('/api/ping')->assertStatus(401);
    }

    public function test_gir_tilgang_til_beskyttede_ruter_etter_innlogging(): void
    {
        $this->postJson('/api/login', ['password' => 'test-passord']);

        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJson(['pong' => true]);
    }

    public function test_logger_ut(): void
    {
        $this->postJson('/api/login', ['password' => 'test-passord']);
        $this->postJson('/api/logout')->assertOk();

        $this->getJson('/api/ping')->assertStatus(401);
    }
}
