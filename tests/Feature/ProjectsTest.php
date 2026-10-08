<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SourceArchive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_owner_can_create_a_new_project_without_a_source_archive(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post('/projects', [
            'name' => 'Workspace Demo',
            'description' => 'A fresh codebase for a demo.',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
        ]);

        $project = Project::query()->sole();
        $response->assertRedirect('/projects/'.$project->id);
        $this->assertSame('NEW_PROJECT', $project->project_type);
        $this->assertSame('Laravel', $project->stack);
        $this->assertDatabaseCount('source_archives', 0);
    }

    public function test_existing_project_zip_is_kept_private_in_quarantine(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $archive = $this->makeZip('src/index.php', '<?php echo "safe";');

        $response = $this->actingAs($owner)->post('/projects', [
            'name' => 'Existing Site',
            'description' => 'Imported source baseline.',
            'project_type' => 'EXISTING_PROJECT',
            'source_archive' => $archive,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $project = Project::query()->sole();
        $source = SourceArchive::query()->sole();

        $response->assertRedirect('/projects/'.$project->id);
        $this->assertSame('EXISTING_PROJECT', $project->project_type);
        $this->assertSame('QUARANTINED', $source->status);
        $this->assertSame($project->id, $source->project_id);
        Storage::disk('local')->assertExists($source->storage_path);
    }

    public function test_zip_slip_entry_is_rejected_before_project_creation(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $archive = $this->makeZip('../outside.php', '<?php echo "unsafe";');

        $this->actingAs($owner)->from('/projects/create')->post('/projects', [
            'name' => 'Unsafe Import',
            'project_type' => 'EXISTING_PROJECT',
            'source_archive' => $archive,
        ])->assertRedirect('/projects/create')->assertSessionHasErrors('source_archive');

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('source_archives', 0);
    }

    public function test_existing_project_requires_a_zip_archive(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->from('/projects/create')->post('/projects', [
            'name' => 'Missing Source',
            'project_type' => 'EXISTING_PROJECT',
        ])->assertRedirect('/projects/create')->assertSessionHasErrors('source_archive');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_sidebar_opens_one_project_selector_for_the_unified_sprint_backlog(): void
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Selector project',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
            'status' => 'SETUP_REQUIRED',
        ]);

        $this->actingAs($owner)
            ->get('/projects?section=sprint-backlog')
            ->assertOk()
            ->assertSee('Pilih project untuk membuka Sprint Backlog')
            ->assertSee(route('projects.sprint-backlog.index', $project), false)
            ->assertSee('Sprint Backlog')
            ->assertDontSee('Product backlog')
            ->assertDontSee('>Sprints</a>', false)
            ->assertDontSee('＋ Buat project');
    }

    private function makeZip(string $entry, string $contents): UploadedFile
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $path = $directory.'/'.uniqid('source-', true).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($entry, $contents);
        $zip->close();

        $file = UploadedFile::fake()->createWithContent('source.zip', file_get_contents($path));
        unlink($path);

        return $file;
    }
}
