<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectProfile extends Model
{
    protected $fillable = [
        'project_id',
        'php_binary',
        'php_version',
        'laravel_version',
        'db_engine',
        'needs_node',
        'subdir',
        'provision_status',
        'provision_error',
        'provisioned_at',
    ];

    protected $casts = [
        'needs_node' => 'boolean',
        'provisioned_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isProvisioned(): bool
    {
        return $this->provision_status === 'READY';
    }
}
