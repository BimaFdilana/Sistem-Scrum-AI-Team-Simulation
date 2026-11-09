<?php

namespace Tests\Feature;

use App\Jobs\SendStoryEstimationReportJob;
use App\Models\Actor;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use App\Services\HermesClient;
use App\Services\StoryEstimationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Laporan estimasi: AI tidak boleh dipanggil di dalam transaksi database, dan
 * kegagalan laporan tidak boleh menghilangkan poin konsensus.
 */
class EstimationReportSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Story $story;

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /** Run REPORT_PENDING dengan bukti diskusi lengkap (deadlock, maksimum = 3). */
    private function pendingRun(): StoryEstimationRun
    {
        $this->owner = User::factory()->create();
        $project = Project::query()->create(['name' => 'Report Safety', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'SETUP_REQUIRED']);
        $sprint = Sprint::query()->create(['project_id' => $project->id, 'backlog_id' => $project->backlog->id, 'name' => 'Sprint 1', 'status' => 'PLANNING', 'revision' => 1]);
        $actor = Actor::query()->where('name', 'Administrator')->firstOrFail();
        $this->story = Story::query()->create([
            'project_id' => $project->id, 'sprint_id' => $sprint->id, 'actor_id' => $actor->id, 'user_story_key' => 'US-01',
            'title' => 'Tombol kembali ke atas', 'sprint_purpose' => 'Pengguna mudah kembali ke atas.', 'acceptance_criteria' => 'Tombol muncul setelah scroll.',
            'scope_status' => 'NEEDS_REFINEMENT', 'revision' => 1,
        ]);

        [$run] = app(StoryEstimationService::class)->claimRun($this->story, $this->owner);
        $run->forceFill(['status' => 'REPORT_PENDING', 'consensus_points' => 3, 'consensus_method' => 'deadlock_max'])->save();
        $specialists = ['backend-developer', 'frontend-developer', 'qa-engineer'];
        foreach ($specialists as $i => $slug) {
            $run->messages()->create([
                'story_revision' => 1, 'round' => 1, 'sender_slug' => $slug, 'recipient_slug' => 'scrum-master',
                'content' => json_encode(['estimate_points' => [1, 2, 3][$i], 'rationale' => 'alasan']), 'estimate_points' => [1, 2, 3][$i], 'explicit_agree' => false,
            ]);
        }
        foreach ($specialists as $recipient) {
            foreach ($specialists as $i => $sender) {
                if ($sender !== $recipient) {
                    $run->messages()->create([
                        'story_revision' => 1, 'round' => 1, 'sender_slug' => $sender, 'recipient_slug' => $recipient,
                        'content' => json_encode(['estimate_points' => [1, 2, 3][$i], 'rationale' => 'alasan']), 'estimate_points' => [1, 2, 3][$i], 'explicit_agree' => false,
                    ]);
                }
            }
        }
        foreach ($specialists as $i => $slug) {
            $run->messages()->create([
                'story_revision' => 1, 'round' => 2, 'sender_slug' => $slug, 'recipient_slug' => 'scrum-master',
                'content' => json_encode(['estimate_points' => [2, 2, 3][$i], 'rationale' => 'alasan']), 'estimate_points' => [2, 2, 3][$i], 'explicit_agree' => $i === 0,
            ]);
        }

        return $run->fresh();
    }

    public function test_scrum_master_is_called_outside_any_database_transaction(): void
    {
        $run = $this->pendingRun();
        $baseline = DB::transactionLevel();
        $levels = [];
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->andReturnUsing(function () use (&$levels) {
            $levels[] = DB::transactionLevel();

            return 'Tim sepakat 3 poin setelah diskusi.';
        });
        $this->app->instance(HermesClient::class, $sm);

        $result = app(StoryEstimationService::class)->report($run, $this->owner);

        $this->assertNotEmpty($levels);
        $this->assertSame(array_fill(0, count($levels), $baseline), $levels, 'panggilan AI tidak boleh berada di dalam transaksi');
        $this->assertSame('COMPLETED', $result->status);
        $this->assertSame(3, $this->story->fresh()->estimate_points);
    }

    public function test_invalid_summary_keeps_run_pending_for_retry(): void
    {
        $run = $this->pendingRun();
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->once()->andReturn('');
        $this->app->instance(HermesClient::class, $sm);

        $result = app(StoryEstimationService::class)->report($run, $this->owner);

        $this->assertSame('REPORT_PENDING', $result->status);
        $this->assertNull($this->story->fresh()->estimate_points);
    }

    public function test_report_job_saves_consensus_after_scrum_master_keeps_failing(): void
    {
        $run = $this->pendingRun();
        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->andReturn('');
        $this->app->instance(HermesClient::class, $sm);
        $job = new SendStoryEstimationReportJob($run->id);
        $job->tries = 1; // percobaan terakhir

        $job->handle(app(StoryEstimationService::class));

        $fresh = $run->fresh();
        $this->assertSame('COMPLETED', $fresh->status);
        $this->assertStringContainsString('Ringkasan otomatis', (string) $fresh->summary);
        $this->assertSame(3, $this->story->fresh()->estimate_points);
    }

    public function test_recover_command_saves_consensus_points(): void
    {
        $run = $this->pendingRun();
        StoryEstimationRun::query()->whereKey($run->id)->update(['updated_at' => now()->subHours(2)]);

        $this->artisan('estimations:recover', ['--minutes' => 45])->assertSuccessful();

        $this->assertSame('COMPLETED', $run->fresh()->status);
        $this->assertSame(3, $this->story->fresh()->estimate_points);
    }

    public function test_fallback_never_assigns_points_to_a_changed_story(): void
    {
        $run = $this->pendingRun();
        $this->story->forceFill(['title' => 'Story berubah', 'revision' => 2])->save();

        $status = app(StoryEstimationService::class)->completeWithFallback($run, 'uji');

        $this->assertSame('STALE', $status);
        $this->assertNull($this->story->fresh()->estimate_points);
    }
}
