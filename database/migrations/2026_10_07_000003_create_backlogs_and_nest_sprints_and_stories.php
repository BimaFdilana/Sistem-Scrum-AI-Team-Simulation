<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backlogs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        foreach (DB::table('projects')->orderBy('id')->get() as $project) {
            DB::table('backlogs')->insert([
                'project_id' => $project->id,
                'name' => $project->name.' Backlog',
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('sprints', function (Blueprint $table): void {
            $table->foreignId('backlog_id')->nullable()->constrained()->nullOnDelete();
        });

        DB::table('sprints')->orderBy('id')->get()->each(function ($sprint): void {
            $backlogId = DB::table('backlogs')->where('project_id', $sprint->project_id)->value('id');
            DB::table('sprints')->where('id', $sprint->id)->update(['backlog_id' => $backlogId]);
        });

        Schema::table('stories', function (Blueprint $table): void {
            $table->string('user_story_key', 32)->nullable();
        });

        DB::table('projects')->orderBy('id')->get()->each(function ($project): void {
            DB::table('stories')->where('project_id', $project->id)->orderBy('id')->get()->values()->each(function ($story, int $index): void {
                DB::table('stories')->where('id', $story->id)->update([
                    'user_story_key' => 'US-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                ]);
            });
        });

        Schema::table('stories', function (Blueprint $table): void {
            $table->unique(['project_id', 'user_story_key']);
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'user_story_key']);
            $table->dropColumn('user_story_key');
        });

        Schema::table('sprints', function (Blueprint $table): void {
            $table->dropForeign(['backlog_id']);
            $table->dropColumn('backlog_id');
        });

        Schema::dropIfExists('backlogs');
    }
};
