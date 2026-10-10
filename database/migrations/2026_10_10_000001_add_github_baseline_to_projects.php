<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('source_repository_url', 500)->nullable()->after('project_type');
            $table->string('source_branch', 255)->nullable()->after('source_repository_url');
            $table->char('source_commit_sha', 40)->nullable()->after('source_branch');
            $table->string('workspace_path', 500)->nullable()->after('source_commit_sha');
            $table->string('source_error', 255)->nullable()->after('workspace_path');
            $table->index(['source_repository_url', 'source_branch']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['source_repository_url', 'source_branch']);
            $table->dropColumn([
                'source_repository_url', 'source_branch', 'source_commit_sha', 'workspace_path', 'source_error',
            ]);
        });
    }
};
