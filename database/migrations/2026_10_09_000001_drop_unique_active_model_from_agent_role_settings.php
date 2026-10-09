<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_role_settings', function (Blueprint $table): void {
            $table->dropUnique('agent_role_settings_active_model_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agent_role_settings', function (Blueprint $table): void {
            $table->unique('active_model', 'agent_role_settings_active_model_unique');
        });
    }
};
