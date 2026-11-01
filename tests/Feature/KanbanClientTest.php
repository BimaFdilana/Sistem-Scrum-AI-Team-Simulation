<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\KanbanClient;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class KanbanClientTest extends TestCase
{
    private string $fakeBin;

    private string $argsFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeBin = storage_path('framework/testing/kanban-fake-'.uniqid().'.sh');
        $this->argsFile = storage_path('framework/testing/kanban-args-'.uniqid().'.txt');
        // Biner hermes palsu: mencatat argumen, lalu menjawab sesuai verb.
        // Urutan argumen KanbanClient: kanban --board <board> <verb> ...
        // dan kanban boards create <slug> ... — jadi deteksi lewat kata
        // 'show' / 'boards' di daftar argumen, bukan posisi tetap.
        $script = "#!/bin/sh\n";
        $script .= "printf '%s\\n' \"\$@\" > ".escapeshellarg($this->argsFile)."\n";
        $script .= "case \" \$* \" in\n";
        $script .= "  *' show '*) echo '{\"task\":{\"id\":\"t_test123\",\"status\":\"done\",\"result\":\"selesai\"}}' ;;\n";
        $script .= "  *' boards '*) echo 'Board created' ;;\n";
        $script .= "  *) echo '{\"task\":{\"id\":\"t_test123\",\"status\":\"todo\"}}' ;;\n";
        $script .= "esac\n";
        file_put_contents($this->fakeBin, $script);
        chmod($this->fakeBin, 0755);
        config(['ai.hermes.binary' => $this->fakeBin]);
    }

    protected function tearDown(): void
    {
        File::delete($this->fakeBin);
        File::delete($this->argsFile);
        parent::tearDown();
    }

    public function test_board_slug_comes_from_project_name(): void
    {
        $project = new Project(['name' => 'Laundry Laravel (Uji)']);
        $project->id = 42;

        $this->assertSame('laundry-laravel-uji', app(KanbanClient::class)->boardSlugForProject($project));
    }

    public function test_create_task_returns_id_and_passes_board_assignee_workspace(): void
    {
        $client = app(KanbanClient::class);
        $client->ensureBoard('laundry-laravel-uji', 'Laundry Laravel (Uji)', 'desc');

        $id = $client->createTask('laundry-laravel-uji', 'Task #1: X', 'Kerjakan X', 'backend-developer', '/tmp/wt-1', 'scrum-exec-1-1-1');
        $this->assertSame('t_test123', $id);

        $args = file($this->argsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $this->assertContains('laundry-laravel-uji', $args);
        $this->assertContains('backend-developer', $args);
        $this->assertContains('dir:/tmp/wt-1', $args);
        $this->assertContains('scrum-exec-1-1-1', $args);
    }

    public function test_show_task_parses_done_status(): void
    {
        $result = app(KanbanClient::class)->showTask('laundry-laravel-uji', 't_test123');

        $this->assertSame('done', $result['status']);
    }
}
