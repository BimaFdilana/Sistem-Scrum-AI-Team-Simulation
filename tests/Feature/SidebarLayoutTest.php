<?php

namespace Tests\Feature;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sidebar bergrup (UTAMA, PROJECT, PENGATURAN) dan halaman per project. */
class SidebarLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_groups_and_removed_items(): void
    {
        $project = Project::query()->create(['name' => 'SIPUA', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $html = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent();

        foreach (['UTAMA', 'PROJECT', 'PENGATURAN', '>Ringkasan</a>', '>Projects</a>', 'Sprint Backlog</a>', 'Chat Scrum Master</a>', 'Demo &amp; Rilis</a>', 'Pengaturan Project</a>', 'Model AI</a>', 'Tim Agen</a>', 'Aktor</a>', 'topbar-bell'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString(route('projects.executions.index', $project), $html);
        $this->assertStringNotContainsString('>GitHub</a>', $html);
        $this->assertStringNotContainsString('nav-dot"></span>Notifikasi', $html);
    }

    public function test_project_pages_render_and_executions_show_what_needs_a_decision(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create(['name' => 'SIPUA', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $done = Story::create(['project_id' => $project->id, 'title' => 'Selesai menunggu', 'estimate_points' => 3]);
        ExecutionRun::query()->create(['story_id' => $done->id, 'requested_by' => $user->id, 'status' => 'COMPLETED', 'mode' => 'direct']);
        $running = Story::create(['project_id' => $project->id, 'title' => 'Sedang jalan', 'estimate_points' => 3]);
        ExecutionRun::query()->create(['story_id' => $running->id, 'requested_by' => $user->id, 'status' => 'EXECUTING', 'mode' => 'direct']);

        $this->actingAs($user)->get(route('projects.executions.index', $project))->assertOk()
            ->assertSee('Selesai menunggu')->assertDontSee('Sedang jalan') // default: perlu keputusan
            ->assertSee('Perlu keputusan');
        $this->actingAs($user)->get(route('projects.executions.index', [$project, 'filter' => 'running']))->assertOk()->assertSee('Sedang jalan');
        $this->actingAs($user)->get('/dashboard')->assertSee('title="Perlu keputusan Anda">1<', false);

        $this->actingAs($user)->get(route('projects.release.show', $project))->assertOk()->assertSee('Isi demo')->assertSee('Jalankan demo');
        $this->actingAs($user)->get(route('projects.settings.show', $project))->assertOk()->assertSee('GERBANG TES QA')->assertDontSee('Jalankan demo');
        $this->actingAs($user)->get(route('projects.show', $project))->assertOk()->assertDontSee('Pengaturan &amp; Demo', false);
    }
}
