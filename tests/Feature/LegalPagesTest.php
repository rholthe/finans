<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_personvernsiden_er_offentlig_tilgjengelig(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Personvernerklæring')
            ->assertSee('Enable Banking')
            ->assertSee('finans.example.com');
    }

    public function test_vilkarsiden_er_offentlig_tilgjengelig(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertSee('Vilkår for bruk')
            ->assertSee('Enable Banking');
    }
}
