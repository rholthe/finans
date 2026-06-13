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
        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.manual_sync_days', 10)
            ->assertJsonPath('data.auto_sync_days', 5);
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
