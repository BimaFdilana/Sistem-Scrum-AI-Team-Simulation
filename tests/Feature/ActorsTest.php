<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_owner_can_manage_actors(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)
            ->get('/actors')
            ->assertOk()
            ->assertSee('Administrator')
            ->assertSee('Pengusul/Verifikator')
            ->assertSee('Wadir');

        $this->post('/actors', ['name' => 'Ketua Program Studi', 'description' => 'Review program proposals.'])
            ->assertRedirect('/actors');

        $actor = Actor::query()->where('name', 'Ketua Program Studi')->sole();
        $this->patch('/actors/'.$actor->id, ['name' => 'Ketua Prodi', 'description' => 'Review program proposals.'])
            ->assertRedirect('/actors');
        $this->assertDatabaseHas('actors', ['id' => $actor->id, 'name' => 'Ketua Prodi']);

        $this->delete('/actors/'.$actor->id)->assertRedirect('/actors');
        $this->assertDatabaseMissing('actors', ['id' => $actor->id]);
    }

    public function test_actor_actions_use_a_responsive_action_group(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get('/actors')
            ->assertOk()
            ->assertSee('actors.css', false)
            ->assertSee('actor-row', false)
            ->assertSee('actor-actions', false)
            ->assertSee('aria-label="Edit actor Administrator"', false)
            ->assertSee('aria-label="Hapus actor Administrator"', false);
    }
}
