<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefinedStoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_saves_sm_refined_story(): void
    {
        $this->fakeEstimation(expectRefined: true);
        [$owner, $project, $story] = $this->makeStory();

        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog');

        $run = StoryEstimationRun::query()->where('story_id', $story->id)->firstOrFail();
        $this->assertSame('COMPLETED', $run->status);

        $story = $story->fresh();
        $this->assertTrue($story->hasRefinement());
        $this->assertSame('Saya Ingin login dengan Google yang aman', $story->refined_title);
        $this->assertSame('Agar pengguna masuk dalam 1 klik', $story->refined_purpose);
        $this->assertStringContainsString('Token disimpan terenkripsi', $story->refined_acceptance_criteria);
        $this->assertSame($run->id, (int) $story->refined_from_run_id);
        $this->assertNotNull($story->refined_at);

        // Versi refined menjadi acuan eksekusi.
        $this->assertSame('Saya Ingin login dengan Google yang aman', $story->effectiveTitle());
        $this->assertSame('Agar pengguna masuk dalam 1 klik', $story->effectivePurpose());
    }

    public function test_invalid_refined_json_does_not_fail_report(): void
    {
        $this->fakeEstimation(expectRefined: false);
        [$owner, $project, $story] = $this->makeStory();

        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs')
            ->assertRedirect('/projects/'.$project->id.'/sprint-backlog');

        $run = StoryEstimationRun::query()->where('story_id', $story->id)->firstOrFail();
        $this->assertSame('COMPLETED', $run->status);
        $this->assertFalse($story->fresh()->hasRefinement());
    }

    public function test_refined_story_resets_when_revision_changes(): void
    {
        [$owner, $project, $story] = $this->makeStory();
        $story->forceFill([
            'refined_title' => 'Saya Ingin versi baru',
            'refined_purpose' => 'Agar lebih jelas',
            'refined_acceptance_criteria' => 'Kriteria baru',
            'refined_at' => now(),
        ])->save();

        $story->revision = 2;
        $story->save();

        $fresh = $story->fresh();
        $this->assertFalse($fresh->hasRefinement());
        $this->assertNull($fresh->refined_title);
        $this->assertNull($fresh->refined_from_run_id);
        // Fallback ke data asli.
        $this->assertSame('Kelola pengajuan', $fresh->effectiveTitle());
    }

    public function test_discussion_page_shows_original_and_refined_tabs(): void
    {
        $this->fakeEstimation(expectRefined: true);
        [$owner, $project, $story] = $this->makeStory();
        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs');

        $this->actingAs($owner)->get('/stories/'.$story->id.'/discussion')
            ->assertOk()
            ->assertSee('Data Asli')
            ->assertSee('Hasil Diskusi SM')
            ->assertSee('Kelola pengajuan')
            ->assertSee('Saya Ingin login dengan Google yang aman');
    }

    private function fakeEstimation(bool $expectRefined): void
    {
        $this->configureEstimationProfiles();
        Http::fake(function (Request $request) use ($expectRefined) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [
                    ['id' => 'be-model', 'owned_by' => 'openai'],
                    ['id' => 'fe-model', 'owned_by' => 'openai'],
                    ['id' => 'qa-model', 'owned_by' => 'openai'],
                    ['id' => 'sm-model', 'owned_by' => 'openai'],
                ]]);
            }
            $content = $request['messages'][1]['content'] ?? '';
            if ($request['model'] === 'sm-model') {
                if (str_contains($content, 'versi FINAL user story')) {
                    $body = $expectRefined
                        ? json_encode([
                            'refined_title' => 'Saya Ingin login dengan Google yang aman',
                            'refined_purpose' => 'Agar pengguna masuk dalam 1 klik',
                            'refined_acceptance_criteria' => "Token disimpan terenkripsi\nAkun baru otomatis terdaftar",
                        ])
                        : 'bukan json valid {{{';

                    return Http::response(['choices' => [['message' => ['content' => $body]]]]);
                }

                return Http::response(['choices' => [['message' => ['content' => 'Scrum Master: ketiganya setuju 5 poin.']]]]);
            }
            $finalConfirmation = str_contains($content, 'KONFIRMASI FINAL PUTARAN 2');
            $secondRound = str_contains($content, 'PUTARAN 2');
            $point = ($secondRound || $finalConfirmation) ? 5 : 3;
            $response = ['estimate_points' => $point, 'rationale' => 'Cakupan keseluruhan dan kriteria diperiksa.'];
            if ($secondRound || $finalConfirmation) {
                $response['response'] = 'Saya membaca pendapat peserta lain dan menyetujui 5 poin.';
                $response['explicit_agree'] = true;
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode($response)]]]]);
        });
    }

    private function configureEstimationProfiles(): void
    {
        // Estimasi kini lewat gateway CLIProxyAPI dengan model per role
        // dari menu (aturan tanpa-default) — bukan lagi endpoint
        // per-role legacy.
        config([
            'ai.cliproxy.agents_enabled' => true,
            'ai.cliproxy.api_key' => 'test-key',
            'ai.cliproxy.base_url' => 'http://127.0.0.1:8317',
        ]);
        $this->mock(\App\Services\AgentConfiguration::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('current')->with('backend-developer')->andReturn(['status' => 'verified', 'model' => 'be-model']);
            $mock->shouldReceive('current')->with('frontend-developer')->andReturn(['status' => 'verified', 'model' => 'fe-model']);
            $mock->shouldReceive('current')->with('qa-engineer')->andReturn(['status' => 'verified', 'model' => 'qa-model']);
            $mock->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'sm-model']);
        });
    }

    private function makeStory(): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Budget Workspace', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED',
        ]);
        $sprint = Sprint::query()->create([
            'project_id' => $project->id, 'backlog_id' => $project->backlog->id,
            'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1,
        ]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $story = Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id,
            'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Kelola pengajuan', 'sprint_purpose' => 'Pengajuan mudah dipantau oleh PO.',
            'acceptance_criteria' => "PO dapat membuat pengajuan.\nSistem menampilkan status pengajuan.",
            'scope_status' => 'NEEDS_REFINEMENT', 'revision' => 1,
        ]);

        return [$owner, $project, $story];
    }
}
