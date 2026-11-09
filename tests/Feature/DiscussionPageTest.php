<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscussionPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeStory(User $owner): Story
    {
        $project = Project::query()->create(['name' => 'DP', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED']);
        $sprint = Sprint::query()->create(['project_id' => $project->id, 'backlog_id' => $project->backlog->id, 'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();

        return Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id, 'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Kelola pengajuan', 'sprint_purpose' => 'Bantu PO memantau', 'acceptance_criteria' => 'Daftar tampil', 'scope_status' => 'APPROVED', 'revision' => 1,
        ]);
    }

    private function makeRun(Story $story, User $owner, string $status = 'COMPLETED', ?string $summary = null): StoryEstimationRun
    {
        return StoryEstimationRun::query()->create([
            'story_id' => $story->id, 'requested_by' => $owner->id, 'story_revision' => $story->revision, 'status' => $status,
            'consensus_points' => $status === 'COMPLETED' ? 5 : null, 'consensus_method' => 'unanimous', 'summary' => $summary,
        ]);
    }

    private function message(StoryEstimationRun $run, string $slug, int $round, array $payload, ?bool $agree = null): void
    {
        $run->messages()->create([
            'story_revision' => $run->story_revision, 'round' => $round, 'sender_slug' => $slug, 'recipient_slug' => 'scrum-master',
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'estimate_points' => $payload['estimate_points'] ?? null, 'explicit_agree' => $agree,
        ]);
    }

    public function test_every_message_is_shown_in_full_without_truncation(): void
    {
        $owner = User::factory()->create();
        $story = $this->makeStory($owner);
        $run = $this->makeRun($story, $owner);
        $long = str_repeat('Alasan sangat panjang tentang cakupan story. ', 40).'AKHIR-PESAN-LENGKAP';
        $this->message($run, 'backend-developer', 1, ['estimate_points' => 5, 'rationale' => $long]);

        $page = $this->actingAs($owner)->get('/stories/'.$story->id.'/discussion')->assertOk();

        $page->assertSee('AKHIR-PESAN-LENGKAP');
        $this->assertStringNotContainsString('...', $this->bubbleText($page->getContent()), 'pesan tidak boleh dipotong dengan "..."');
        $this->assertStringNotContainsString('…', $this->bubbleText($page->getContent()));
    }

    public function test_long_scrum_master_summary_is_shown_in_full(): void
    {
        $owner = User::factory()->create();
        $story = $this->makeStory($owner);
        $summary = str_repeat('Ringkasan laporan SM yang panjang. ', 50).'AKHIR-RINGKASAN';
        $run = $this->makeRun($story, $owner, 'COMPLETED', $summary);
        $this->message($run, 'qa-engineer', 1, ['estimate_points' => 5, 'rationale' => 'ok']);

        $this->actingAs($owner)->get('/stories/'.$story->id.'/discussion')->assertOk()->assertSee('AKHIR-RINGKASAN')->assertSee('Ringkasan Scrum Master');
    }

    public function test_round_two_message_shows_both_rationale_and_response(): void
    {
        $owner = User::factory()->create();
        $story = $this->makeStory($owner);
        $run = $this->makeRun($story, $owner);
        $this->message($run, 'frontend-developer', 2, [
            'estimate_points' => 5, 'rationale' => 'ALASAN-PUTARAN-DUA', 'response' => 'TANGGAPAN-KE-TIM', 'explicit_agree' => true,
        ], true);

        $this->actingAs($owner)->get('/stories/'.$story->id.'/discussion')->assertOk()
            ->assertSee('ALASAN-PUTARAN-DUA')->assertSee('TANGGAPAN-KE-TIM')
            ->assertSee('Putaran 2')->assertSee('setuju');
    }

    public function test_messages_are_grouped_by_round_and_older_runs_are_collapsed(): void
    {
        $owner = User::factory()->create();
        $story = $this->makeStory($owner);
        $old = $this->makeRun($story, $owner, 'FAILED');
        StoryEstimationRun::query()->whereKey($old->id)->update(['created_at' => now()->subDay()]);
        $new = $this->makeRun($story, $owner);
        $this->message($new, 'backend-developer', 1, ['estimate_points' => 3, 'rationale' => 'a']);
        $this->message($new, 'backend-developer', 2, ['estimate_points' => 5, 'rationale' => 'b', 'response' => 'c'], true);

        $html = $this->actingAs($owner)->get('/stories/'.$story->id.'/discussion')->assertOk()->getContent();

        $this->assertStringContainsString('Putaran 1 · usulan awal', $html);
        $this->assertStringContainsString('Putaran 2 · tanggapan dan kesepakatan', $html);
        $this->assertStringContainsString('Diskusi sebelumnya', $html);
        $this->assertSame(1, substr_count($html, 'class="dc-older"'));
    }

    public function test_empty_state_points_to_the_project_page(): void
    {
        $owner = User::factory()->create();
        $story = $this->makeStory($owner);

        $this->actingAs($owner)->get('/stories/'.$story->id.'/discussion')->assertOk()
            ->assertSee('Belum ada diskusi')->assertSee(route('projects.show', $story->project_id), false);
    }

    /** Teks di dalam gelembung pesan saja (tanpa JS/CSS halaman). */
    private function bubbleText(string $html): string
    {
        preg_match_all('/<div class="dc-text">(.*?)<\/div>/s', $html, $m);

        return implode("\n", $m[1] ?? []);
    }
}
