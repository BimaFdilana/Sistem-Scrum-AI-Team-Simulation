<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageRecord extends Model
{
    protected $fillable = [
        'provider', 'model', 'prompt_tokens', 'completion_tokens', 'total_tokens',
        'execution_run_id', 'execution_task_id', 'source',
    ];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
        ];
    }
}
