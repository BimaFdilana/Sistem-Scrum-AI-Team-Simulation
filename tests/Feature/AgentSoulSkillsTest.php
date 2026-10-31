<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use App\Support\Agents\AgentSoul;
use App\Support\Workspace\ProjectInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentSoulSkillsTest extends TestCase
{
    use RefreshDatabase;

    public function test_souls_are_configured_for_all_roles(): void
    {
        foreach (['scrum-master', 'backend-developer', 'frontend-developer', 'qa-engineer'] as $slug) {
            $this->assertNotEmpty(AgentSoul::for($slug), "soul missing for {$slug}");
            $this->assertNotEmpty(AgentSoul::promptLine($slug));
        }
        $this->assertSame(['schema_summary', 'api_inventory'], AgentSoul::skills('backend-developer'));
        $this->assertSame(['component_inventory', 'route_list'], AgentSoul::skills('frontend-developer'));
        $this->assertSame(['criteria_analyzer', 'risk_patterns'], AgentSoul::skills('qa-engineer'));
        $this->assertSame([], AgentSoul::skills('scrum-master'));
    }

    public function test_project_inspector_builds_capped_read_only_summaries(): void
    {
        $inspector = new ProjectInspector($this->fixturePath(), 'test-fixture');

        $components = $inspector->componentInventory();
        $this->assertStringContainsString('alert', $components);
        $this->assertStringContainsString('modal', $components);

        $schema = $inspector->schemaSummary();
        $this->assertStringContainsString('orders', $schema);
        $this->assertStringContainsString('user_id', $schema);

        $routes = $inspector->routeList();
        $this->assertStringContainsString('/dashboard', $routes);

        $api = $inspector->apiInventory();
        $this->assertStringContainsString('OrderController', $api);

        // Tidak ada file yang ditulis oleh inspector.
        $this->assertSame(2, count(glob($this->fixturePath().'/resources/views/components/*.blade.php')));
    }

    public function test_inspector_returns_empty_for_missing_workspace(): void
    {
        $inspector = new ProjectInspector('/tidak/ada', 'test-missing');

        $this->assertSame('', $inspector->componentInventory());
        $this->assertSame('', $inspector->schemaSummary());
        $this->assertSame('', $inspector->routeList());
        $this->assertSame('', $inspector->skillContext('component_inventory'));
        $this->assertSame('', $inspector->skillContext('unknown_skill'));
    }

    public function test_estimation_prompts_carry_asymmetric_skill_context(): void
    {
        $this->configureEstimationProfiles();
        $prompts = [];
        Http::fake(function (Request $request) use (&$prompts) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [
                    ['id' => 'be-model', 'owned_by' => 'openai'],
                    ['id' => 'fe-model', 'owned_by' => 'openai'],
                    ['id' => 'qa-model', 'owned_by' => 'openai'],
                    ['id' => 'sm-model', 'owned_by' => 'openai'],
                ]]);
            }
            $content = $request['messages'][1]['content'] ?? '';
            // Simpan hanya prompt PUTARAN 1 (round 2 menimpa dengan prompt konfirmasi).
            if (str_contains($content, 'PUTARAN 1')) {
                foreach (['be-model' => 'be', 'fe-model' => 'fe', 'qa-model' => 'qa'] as $model => $key) {
                    if ($request['model'] === $model) {
                        $prompts[$key] = $content;
                    }
                }
            }
            if ($request['model'] === 'sm-model') {
                return Http::response(['choices' => [['message' => ['content' => 'Ringkasan.']]]]);
            }
            $isRound1 = str_contains($content, 'PUTARAN 1');
            $response = ['estimate_points' => 5, 'rationale' => 'Analisis.'];
            if (! $isRound1) {
                $response += ['response' => 'Setuju.', 'explicit_agree' => true];
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode($response)]]]]);
        });

        [$owner, $project, $story] = $this->makeStoryWithWorkspace();
        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs');

        $bePrompt = $prompts['be'] ?? '';
        $fePrompt = $prompts['fe'] ?? '';
        $qaPrompt = $prompts['qa'] ?? '';

        // Informasi asimetris: BE lihat skema, FE lihat komponen — tidak sebaliknya.
        $this->assertStringContainsString('Konteks khusus peranmu', $bePrompt);
        $this->assertStringContainsString('orders', $bePrompt);
        $this->assertStringNotContainsString('komponen Blade', $bePrompt);

        $this->assertStringContainsString('Konteks khusus peranmu', $fePrompt);
        $this->assertStringContainsString('komponen Blade', $fePrompt);
        $this->assertStringNotContainsString('Skema database', $fePrompt);

        // QA diminta mengurai skenario uji bernomor.
        $this->assertStringContainsString('skenario uji bernomor', $qaPrompt);

        // Agen tidak disuruh membeberkan daftar file.
        $this->assertStringContainsString('jangan daftarkan file/direktori', $bePrompt);
    }

    public function test_no_skill_context_without_workspace(): void
    {
        $this->configureEstimationProfiles();
        $prompts = [];
        Http::fake(function (Request $request) use (&$prompts) {
            if (str_ends_with($request->url(), '/v1/models')) {
                return Http::response(['data' => [
                    ['id' => 'be-model', 'owned_by' => 'openai'],
                    ['id' => 'fe-model', 'owned_by' => 'openai'],
                    ['id' => 'qa-model', 'owned_by' => 'openai'],
                    ['id' => 'sm-model', 'owned_by' => 'openai'],
                ]]);
            }
            $content = $request['messages'][1]['content'] ?? '';
            if (str_contains($content, 'PUTARAN 1') && $request['model'] === 'be-model') {
                $prompts['be'] = $content;
            }
            if ($request['model'] === 'sm-model') {
                return Http::response(['choices' => [['message' => ['content' => 'Ringkasan.']]]]);
            }
            $isRound1 = str_contains($content, 'PUTARAN 1');
            $response = ['estimate_points' => 5, 'rationale' => 'Analisis.'];
            if (! $isRound1) {
                $response += ['response' => 'Setuju.', 'explicit_agree' => true];
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode($response)]]]]);
        });

        // Project tanpa workspace_path: tidak ada konteks skill.
        [$owner, $project, $story] = $this->makeStoryWithWorkspace(null);
        $this->actingAs($owner)->post('/stories/'.$story->id.'/estimation-runs');

        $bePrompt = $prompts['be'] ?? '';
        $this->assertStringNotContainsString('Konteks khusus peranmu', $bePrompt);
    }

    private function fixturePath(): string
    {
        return base_path('tests/Fixtures/fake-project');
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

    /** @return array{User, Project, Story} */
    private function makeStoryWithWorkspace(?string $workspace = 'fixture'): array
    {
        Cache::flush();
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Fake Workspace', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel',
            'status' => 'SETUP_REQUIRED',
            'workspace_path' => $workspace === null ? null : $this->fixturePath(),
        ]);
        $sprint = Sprint::query()->create([
            'project_id' => $project->id, 'backlog_id' => $project->backlog->id,
            'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1,
        ]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $story = Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id,
            'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Kelola pesanan', 'sprint_purpose' => 'Pesanan mudah dipantau.',
            'acceptance_criteria' => "PO dapat membuat pesanan.\nSistem menampilkan status pesanan.",
            'scope_status' => 'NEEDS_REFINEMENT', 'revision' => 1,
        ]);

        return [$owner, $project, $story];
    }
}
