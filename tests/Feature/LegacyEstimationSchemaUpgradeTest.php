<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyEstimationSchemaUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_estimation_schema_is_upgraded_without_deleting_runs_or_agent_evidence(): void
    {
        Schema::dropIfExists('story_estimation_messages');
        Schema::dropIfExists('story_estimation_agent_runs');
        Schema::dropIfExists('story_estimation_runs');
        Schema::create('story_estimation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('story_id');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedInteger('story_revision');
            $table->string('status', 24);
            $table->longText('summary')->nullable();
            $table->unsignedTinyInteger('approved_points')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('story_estimation_agent_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_estimation_run_id')->constrained()->cascadeOnDelete();
            $table->string('profile_slug');
            $table->unsignedTinyInteger('round');
            $table->string('status');
            $table->unsignedTinyInteger('estimate_points')->nullable();
            $table->text('rationale')->nullable();
            $table->timestamps();
        });
        DB::table('story_estimation_runs')->insert([
            ['id' => 1, 'story_id' => 42, 'requested_by' => 1, 'story_revision' => 1, 'status' => 'RUNNING', 'summary' => 'bukti lama pertama', 'created_at' => now()->subMinutes(2), 'updated_at' => now()],
            ['id' => 2, 'story_id' => 42, 'requested_by' => 1, 'story_revision' => 1, 'status' => 'COMPLETED', 'summary' => 'bukti lama kedua', 'created_at' => now()->subMinute(), 'updated_at' => now()],
        ]);
        DB::table('story_estimation_agent_runs')->insert([
            'story_estimation_run_id' => 1, 'profile_slug' => 'qa-engineer', 'round' => 1,
            'status' => 'COMPLETED', 'estimate_points' => 5, 'rationale' => 'Penilaian lama',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_09_000002_upgrade_story_estimation_consensus.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('story_estimation_messages'));
        $this->assertTrue(Schema::hasColumn('story_estimation_runs', 'consensus_points'));
        $this->assertTrue(Schema::hasColumn('story_estimation_runs', 'story_snapshot_hash'));
        $this->assertTrue(Schema::hasColumn('story_estimation_runs', 'report_sent_at'));
        $this->assertSame(2, DB::table('story_estimation_runs')->count());
        $this->assertSame('LEGACY_UNVERIFIED', DB::table('story_estimation_runs')->where('id', 1)->value('status'));
        $this->assertSame('LEGACY_UNVERIFIED', DB::table('story_estimation_runs')->where('id', 2)->value('status'));
        $this->assertSame('bukti lama pertama', DB::table('story_estimation_runs')->where('id', 1)->value('summary'));
        $this->assertSame(1, DB::table('story_estimation_agent_runs')->count());
        $this->assertContains('story_estimation_active_revision_unique', array_column(DB::select("PRAGMA index_list('story_estimation_runs')"), 'name'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_upgrade_does_not_modify_an_already_current_consensus_schema(): void
    {
        $migration = require database_path('migrations/2026_10_09_000002_upgrade_story_estimation_consensus.php');
        $migration->up();
        $this->assertTrue(Schema::hasTable('story_estimation_messages'));
        $this->assertContains('story_estimation_active_revision_unique', array_column(DB::select("PRAGMA index_list('story_estimation_runs')"), 'name'));
    }
}
