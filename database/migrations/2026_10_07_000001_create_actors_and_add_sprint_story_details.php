<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        \Illuminate\Support\Facades\DB::table('actors')->insert(array_map(
            fn (string $name): array => ['name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['Administrator', 'Pengusul/Verifikator', 'Wadir'],
        ));

        Schema::table('stories', function (Blueprint $table): void {
            $table->longText('acceptance_criteria')->nullable()->change();
            $table->foreignId('actor_id')->nullable()->after('sprint_id')->constrained()->nullOnDelete();
            $table->text('sprint_purpose')->nullable()->after('actor_id');
            $table->string('sprint_story_status', 32)->default('NOT_STARTED')->after('sprint_purpose');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropForeign(['actor_id']);
            $table->dropColumn(['actor_id', 'sprint_purpose', 'sprint_story_status']);
            $table->longText('acceptance_criteria')->nullable(false)->change();
        });

        Schema::dropIfExists('actors');
    }
};
