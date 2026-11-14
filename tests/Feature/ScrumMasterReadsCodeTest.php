<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;
use App\Services\AgentConfiguration;
use App\Services\ChatDisclosurePolicy;
use App\Support\Chat\CodeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Scrum Master membaca kode project (hanya baca) untuk menjawab dan merumuskan perintah. */
class ScrumMasterReadsCodeTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    private function git(string ...$args): void
    {
        (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $this->repo))->mustRun();
    }

    private function project(): Project
    {
        $this->repo = storage_path('framework/testing/code-'.uniqid());
        foreach (['app/Http/Controllers', 'resources/views/usulan', 'vendor/pkg', 'app/Models'] as $dir) {
            mkdir($this->repo.'/'.$dir, 0700, true);
        }
        file_put_contents($this->repo.'/app/Http/Controllers/UsulanController.php', "<?php\nclass UsulanController {\n    public function index() {\n        \$pagu = PaguUnit::where('unit_id', auth()->user()->unit_id)->value('nominal');\n        return view('usulan.index', ['pagu' => \$pagu]);\n    }\n}\n");
        file_put_contents($this->repo.'/resources/views/usulan/index.blade.php', "@if (\$pagu)\n Rp {{ number_format(\$pagu) }}\n@else\n Fleksibel / Tidak Ada Batasan\n@endif\n");
        file_put_contents($this->repo.'/app/Models/Unit.php', "<?php\nclass Unit {}\n");
        file_put_contents($this->repo.'/.env', "DB_PASSWORD=rahasia-pagu\n");
        file_put_contents($this->repo.'/vendor/pkg/Pagu.php', '<?php // pagu vendor');
        $this->git('init', '-q', '-b', 'main');
        $this->git('add', '-f', '.');
        $this->git('commit', '-q', '-m', 'awal');

        return Project::query()->create(['name' => 'SIPUA', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY', 'workspace_path' => $this->repo]);
    }

    public function test_relevant_code_is_found_and_secrets_or_vendor_are_never_sent(): void
    {
        $project = $this->project();

        $context = CodeContext::for($project, 'Kenapa pagu tampil Fleksibel di halaman Usulan Saya?');

        $this->assertStringContainsString('### app/Http/Controllers/UsulanController.php', $context);
        $this->assertStringContainsString('### resources/views/usulan/index.blade.php', $context);
        $this->assertStringContainsString("PaguUnit::where('unit_id'", $context);
        $this->assertStringContainsString('HANYA membaca', $context);
        $this->assertStringNotContainsString('rahasia-pagu', $context);
        $this->assertStringNotContainsString('vendor/pkg', $context);
        $this->assertStringNotContainsString('Unit.php', $context); // tidak relevan dengan pertanyaan
    }

    public function test_laporan_chat_sends_relevant_code_to_scrum_master(): void
    {
        $project = $this->project();
        $backlog = $project->backlog()->firstOrCreate([], ['name' => 'B']);
        $sprint = Sprint::create(['backlog_id' => $backlog->id, 'project_id' => $project->id, 'name' => 'Sprint 2', 'status' => 'ACTIVE', 'revision' => 1]);
        config(['ai.cliproxy.agents_enabled' => true, 'ai.cliproxy.api_key' => 'k', 'ai.cliproxy.base_url' => 'http://127.0.0.1:8317']);
        $this->mock(AgentConfiguration::class, fn (MockInterface $m) => $m->shouldReceive('current')->with('scrum-master')->andReturn(['status' => 'verified', 'model' => 'm1']));
        Http::fake([
            'http://127.0.0.1:8317/v1/models' => Http::response(['data' => [['id' => 'm1', 'owned_by' => 'x']]]),
            'http://127.0.0.1:8317/v1/chat/completions' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Karena kolom nominal kosong.']]]]),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/chat/messages', ['message' => 'Di aplikasi ini, kenapa pagu tampil Fleksibel di halaman Usulan Saya? Tunjukkan source code-nya', 'sprint_id' => $sprint->id])
            ->assertOk()->assertJsonPath('assistant_message.content', 'Karena kolom nominal kosong.');

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/chat/completions')) {
                return false;
            }
            $messages = $request->data()['messages'];
            $last = (string) (end($messages)['content'] ?? '');

            return str_contains($last, '### app/Http/Controllers/UsulanController.php') && ! str_contains($last, 'rahasia-pagu');
        });
    }

    public function test_project_context_questions_about_this_app_code_are_not_blocked_but_platform_ones_are(): void
    {
        $policy = app(ChatDisclosurePolicy::class);

        $this->assertFalse($policy->isRestrictedRequest('Di aplikasi ini, file mana source code yang mengatur pagu?', true));
        $this->assertTrue($policy->isRestrictedRequest('Tunjukkan source code server scrumspace', true));
    }
}
