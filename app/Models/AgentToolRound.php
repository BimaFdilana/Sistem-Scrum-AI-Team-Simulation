<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu putaran loop AgentToolRunner: model yang melayani, tool yang
 * dipanggil, dan hasil tiap tool — dipangkas agar tidak membengkak.
 * Ditulis best-effort selama eksekusi/probe; tidak pernah menggagalkan
 * run bila pencatatan gagal. Baris probe (source=probe) dibedakan dari
 * baris eksekusi lewat source + probe_token.
 */
class AgentToolRound extends Model
{
    protected $fillable = [
        'execution_run_id',
        'execution_task_id',
        'source',
        'probe_token',
        'round',
        'model',
        'tool_calls',
        'outcomes',
        'assistant_text',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'outcomes' => 'array',
    ];
}
