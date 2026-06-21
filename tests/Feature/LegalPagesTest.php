<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_personvernsiden_viser_konfigurert_operatorinfo(): void
    {
        config([
            'legal.domain' => 'budsjett.example.com',
            'legal.operator_name' => 'Test Operatør',
            'legal.operator_email' => 'kontakt@example.com',
        ]);

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Personvernerklæring')
            ->assertSee('Enable Banking')
            ->assertSee('budsjett.example.com')
            ->assertSee('Test Operatør')
            ->assertSee('kontakt@example.com');
    }

    public function test_personvernsiden_skjuler_e_post_naar_den_ikke_er_satt(): void
    {
        config(['legal.operator_email' => null]);

        $this->get('/privacy')
            ->assertOk()
            ->assertDontSee('mailto:');
    }

    public function test_vilkarsiden_viser_konfigurert_domene(): void
    {
        config(['legal.domain' => 'budsjett.example.com']);

        $this->get('/terms')
            ->assertOk()
            ->assertSee('Vilkår for bruk')
            ->assertSee('Enable Banking')
            ->assertSee('budsjett.example.com');
    }
}
