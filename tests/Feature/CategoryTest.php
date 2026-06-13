<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    public function test_krever_innlogging(): void
    {
        $this->flushSession();

        $this->getJson('/api/category-groups')->assertStatus(401);
    }

    public function test_lister_grupper_med_kategorier(): void
    {
        $group = CategoryGroup::factory()->create(['name' => 'Faste utgifter']);
        Category::factory()->for($group, 'group')->create(['name' => 'Strøm']);

        $this->getJson('/api/category-groups')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Faste utgifter')
            ->assertJsonPath('data.0.categories.0.name', 'Strøm');
    }

    public function test_oppretter_gruppe_og_kategori(): void
    {
        $group = $this->postJson('/api/category-groups', ['name' => 'Mat'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Mat')
            ->json('data.id');

        $this->postJson('/api/categories', [
            'category_group_id' => $group,
            'name' => 'Dagligvarer',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dagligvarer')
            ->assertJsonPath('data.category_group_id', $group);
    }

    public function test_avviser_kategori_uten_gyldig_gruppe(): void
    {
        $this->postJson('/api/categories', ['name' => 'X', 'category_group_id' => 999])
            ->assertStatus(422);
    }

    public function test_oppdaterer_kategori(): void
    {
        $category = Category::factory()->create(['name' => 'Gammel']);

        $this->patchJson("/api/categories/{$category->id}", ['name' => 'Ny'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ny');
    }

    public function test_sletting_av_gruppe_fjerner_kategorier(): void
    {
        $group = CategoryGroup::factory()->create();
        $category = Category::factory()->for($group, 'group')->create();

        $this->deleteJson("/api/category-groups/{$group->id}")->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }
}
