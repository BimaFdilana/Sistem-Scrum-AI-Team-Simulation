<?php

namespace App\Models;

use App\Support\Execution\BatchQa;
use App\Support\Execution\ExecutionScheduler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExecutionRun extends Model
{
    public const NEEDS_PO = 'NEEDS_PO';

    protected $fillable = [
        'story_id', 'repair_order_id', 'requested_by', 'status', 'executor_policy', 'mode', 'qa_mode', 'qa_rounds', 'qa_started_at', 'qa_finished_at', 'summary', 'error_code', 'completed_at',
        'github_branch', 'base_branch', 'work_branch',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function repairOrder(): BelongsTo
    {
        return $this->belongsTo(RepairOrder::class);
    }

    /** Project target eksekusi: dari story atau dari perintah perbaikan. */
    public function project(): ?Project
    {
        return $this->story?->project ?? $this->repairOrder?->project;
    }

    /** Label subjek untuk judul/permintaan izin ("US-3 — ..." atau "Perintah #2 — ..."). */
    public function subjectLabel(): string
    {
        if ($this->story) {
            return $this->story->display_id.' — '.$this->story->effectiveTitle();
        }
        if ($this->repairOrder) {
            return 'Perintah #'.$this->repairOrder->id.' — '.$this->repairOrder->title;
        }

        return 'Eksekusi #'.$this->id;
    }

    public function subjectTitle(): string
    {
        return $this->story?->effectiveTitle()
            ?? $this->repairOrder?->title
            ?? 'Eksekusi #'.$this->id;
    }

    public function subjectCriteria(): string
    {
        return $this->story?->effectiveAcceptanceCriteria()
            ?? $this->repairOrder?->summary
            ?? '-';
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ExecutionTask::class);
    }

    /**
     * Task dalam urutan eksekusi: sort_order dari breakdown SM, lalu id
     * sebagai penyeimbang (run lama yang sort_order-nya semua 0 tetap
     * terurut sesuai urutan pembuatan).
     */
    public function orderedTasks(): HasMany
    {
        return $this->hasMany(ExecutionTask::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Task PENDING berikutnya sesuai urutan (model berurutan), atau null. */
    public function nextPendingTask(): ?ExecutionTask
    {
        return $this->orderedTasks()->where('status', 'PENDING')->first();
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ExecutionFinding::class)->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ExecutionMessage::class);
    }

    public function permissionRequest(): HasOne
    {
        return $this->hasOne(PermissionRequest::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            'QUEUED', 'BREAKDOWN', 'AWAITING_PERMISSION', 'EXECUTING', 'REVIEWING', 'MERGING',
        ], true);
    }

    /** Run berhenti menunggu keputusan PO (lanjutkan atau tandai gagal). */
    public function isWaitingForPo(): bool
    {
        return $this->status === self::NEEDS_PO;
    }

    /**
     * Pindah status secara atomik (compare-and-set): hanya berhasil bila
     * status saat ini masih salah satu dari $from. Mencegah dua proses
     * (watchdog, job, PO) saling menimpa status yang sudah berubah.
     *
     * @param  array<int, string>  $from
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(string $to, array $from, array $attributes = []): bool
    {
        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', $from)
            ->update(array_merge($attributes, ['status' => $to, 'updated_at' => now()]));

        if ($updated > 0) {
            $this->refresh();
        }

        return $updated > 0;
    }

    /**
     * PO melanjutkan run NEEDS_PO: task terblokir dijalankan ulang dari awal.
     * False bila run tidak sedang menunggu PO (mis. sudah dilanjutkan orang lain).
     */
    public function resumeFromPo(): bool
    {
        // Story/perintah yang sudah diputuskan PO (Berhasil/Gagal) terkunci dari eksekusi ulang.
        if ($this->story?->isAccepted() || $this->repairOrder?->isAccepted()) {
            return false;
        }

        $qaStop = str_starts_with((string) $this->error_code, 'QA_');
        $attributes = ['error_code' => null, 'summary' => null];
        if ($qaStop) {
            // QA menolak setelah jatah perbaikan habis: beri jatah baru agar
            // temuan bisa diperbaiki lalu dinilai ulang.
            $attributes += ['qa_rounds' => 0, 'qa_started_at' => null];
        }

        if (! $this->transitionTo('EXECUTING', [self::NEEDS_PO], $attributes)) {
            return false;
        }

        $this->tasks()->where('status', 'BLOCKED')->update([
            'status' => 'PENDING', 'stall_count' => 0, 'last_error' => null, 'last_progress_at' => now(),
        ]);
        $this->messages()->create([
            'sender_slug' => 'product-owner',
            'recipient_slug' => 'scrum-master',
            'content' => 'PO melanjutkan eksekusi; task terblokir dijalankan ulang.',
        ]);
        app(ExecutionScheduler::class)->dispatchReady($this);

        if ($qaStop) {
            app(BatchQa::class)->maybeStart($this);
        }

        return true;
    }

    public function isTerminal(): bool
    {
        // FAILED ikut terminal: run yang gagal di tengah jalan (AI timeout,
        // izin ditolak, workspace hilang) harus bisa ditutup PO dengan
        // keputusan final, bukan menggantung selamanya.
        return in_array($this->status, ['COMPLETED', 'BLOCKED', 'FAILED'], true);
    }

    protected static function booted(): void
    {
        // NEEDS_PO hanya bisa keluar lewat transitionTo()/resumeFromPo(). Job yang
        // masih berjalan (model usang di memorinya) tidak boleh menimpa status
        // ini secara diam-diam, apa pun status yang ia kira sedang berlaku.
        static::updating(function (ExecutionRun $run): void {
            if (! $run->isDirty('status') || $run->status === self::NEEDS_PO) {
                return;
            }
            $current = static::query()->whereKey($run->getKey())->value('status');
            if ($current === self::NEEDS_PO) {
                $run->status = self::NEEDS_PO;
            }
        });

        // Sinkronkan status perintah perbaikan saat execution run-nya terminal.
        static::updated(function (ExecutionRun $run): void {
            if (! $run->wasChanged('status') || ! $run->repair_order_id) {
                return;
            }
            $orderStatus = [
                'COMPLETED' => 'COMPLETED',
                'BLOCKED' => 'BLOCKED',
                'FAILED' => 'FAILED',
            ][$run->status] ?? null;
            if ($orderStatus !== null) {
                $run->repairOrder?->forceFill(['status' => $orderStatus])->save();
            }
        });
    }
}
