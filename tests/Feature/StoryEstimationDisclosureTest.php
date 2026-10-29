<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentProfileClient;
use App\Services\HermesClient;
use App\Services\StoryEstimationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StoryEstimationDisclosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_qa_vote_with_security_words_reaches_consensus(): void
    {
        // Regresi kasus QA US-01 Run #6: rationale yang menyebut "server",
        // "credentials", dan "secrets" (kosakata sah penalaran QA atas
        // project PO) tidak boleh lagi ditolak pemeriksaan keamanan.
        [$story, $owner] = $this->story();

        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->andReturnUsing(function ($slug, $prompt) {
            if (str_contains($prompt, 'PUTARAN 1')) {
                return '{"estimate_points":5,"rationale":"Perlu uji di server dan cek credentials flow, pastikan secrets tidak bocor."}';
            }

            return json_encode([
                'estimate_points' => 5,
                'rationale' => 'Setuju, skenario uji di server sudah cukup.',
                'response' => 'Menanggapi tim: setuju.',
                'explicit_agree' => true,
            ]);
        });
        $this->app->instance(AgentProfileClient::class, $agents);
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->andReturn('Laporan berdasar diskusi.');
        $this->app->instance(HermesClient::class, $sm);

        $run = app(StoryEstimationService::class)->estimate($story, $owner);

        $this->assertSame('COMPLETED', $run->status);
        $this->assertSame(5, $run->consensus_points);
        $this->assertSame(5, $story->fresh()->estimate_points);
    }

    private function story(): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create(['name' => 'Estimasi', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED']);
        $sprint = Sprint::query()->create(['project_id' => $project->id, 'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1]);
        $story = Story::query()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'user_story_key' => 'US-01',
            'title' => 'Fitur estimasi',
            'acceptance_criteria' => 'Kriteria nyata.',
            'scope_status' => 'NEEDS_REFINEMENT',
            'revision' => 1,
        ]);

        return [$story, $owner];
    }
}
