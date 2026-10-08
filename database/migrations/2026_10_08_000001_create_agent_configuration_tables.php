<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_config_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 64);
            $table->string('profile', 64);
            $table->unsignedInteger('config_version');
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->string('provider', 128)->default('');
            $table->string('model')->default('');
            $table->text('instructions');
            $table->text('soul');
            $table->text('policy_snapshot');
            $table->json('skills');
            $table->json('skill_snapshot');
            $table->string('status', 16);
            $table->timestamp('created_at');
            $table->unique(['role', 'config_version']);
        });
        Schema::create('agent_role_settings', function (Blueprint $table): void {
            $table->string('role', 64)->primary();
            $table->foreignId('version_id')->constrained('agent_config_versions');
            $table->unsignedInteger('config_version');
            $table->string('active_model')->nullable()->unique();
        });
        Schema::create('agent_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 64);
            $table->string('profile', 64);
            $table->unsignedInteger('config_version')->nullable();
            $table->string('requested_provider', 128)->nullable();
            $table->string('requested_model')->nullable();
            $table->string('served_provider', 128)->nullable();
            $table->string('served_model')->nullable();
            $table->string('verification', 16);
            $table->string('failure_code', 48)->nullable();
            $table->string('session_hash', 64);
            $table->string('configuration_hash', 64);
            $table->string('instructions_hash', 64)->nullable();
            $table->string('soul_hash', 64)->nullable();
            $table->string('policy_hash', 64)->nullable();
            $table->json('skills');
            $table->timestamp('created_at');
        });

        // This workspace uses SQLite. Prevent even accidental query-builder rewrites of audit snapshots.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach (['agent_config_versions', 'agent_dispatches'] as $table) {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    $name = $table.'_immutable_'.strtolower($operation);
                    Schema::getConnection()->statement("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Immutable agent audit snapshot'); END");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_dispatches');
        Schema::dropIfExists('agent_role_settings');
        Schema::dropIfExists('agent_config_versions');
    }
};
