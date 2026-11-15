<?php

namespace App\Jobs;

use App\Support\Ai\QuotaWatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Cek pertama begitu kuota habis tercatat: ambil waktu pulih dari akun (tanpa kirim pesan ke model). */
class CheckAiQuotaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(QuotaWatcher $watcher): void
    {
        $watcher->check(force: true, allowChatProbe: false);
    }
}
