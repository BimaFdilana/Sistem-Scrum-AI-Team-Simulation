<?php

namespace App\Console\Commands;

use App\Models\AgentToolRound;
use App\Models\AiUsageRecord;
use App\Services\AgentProfileClient;
use App\Services\AgentToolRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Probe loop tool-call pada model tertentu, tanpa menyentuh project,
 * database eksekusi, atau antrean: worktree-nya direktori sementara
 * berisi fixture kecil, yang selalu dihapus setelah probe — berhasil
 * atau gagal. Dipakai untuk menjawab "apakah kombinasi model+loop ini
 * bisa menyelesaikan tugas tool sama sekali?" sebelum eksekusi sungguhan
 * dimulai (gerbang pre-flight, diagnosis 31 Okt 2026).
 *
 * Hanya lewat terminal (Artisan). Bukan route web, bukan endpoint publik.
 */
class ProbeAgentTool extends Command
{
    protected $signature = 'agent:probe
        {--model= : Model gateway yang diuji (default: model peran --role)}
        {--role=backend-developer : Peran yang prompt sistemnya dipakai}';

    protected $description = 'Uji loop tool-call pada satu model dengan tugas kecil di worktree sementara.';

    public function handle(AgentToolRunner $runner, AgentProfileClient $agents): int
    {
        $role = (string) $this->option('role');
        $model = (string) ($this->option('model') ?: '');
        if ($model === '') {
            $model = $agents->toolModel($role) ?? '';
        }
        if ($model === '') {
            $this->error('Tidak ada model untuk peran '.$role.' (dan --model tidak diisi).');

            return self::FAILURE;
        }

        $token = 'probe-'.uniqid();
        $dir = sys_get_temp_dir().'/agent-probe-'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir.'/hello.txt', 'hello');

        $prompt = "Baca hello.txt, lalu buat file PROBE.md berisi tepat satu baris 'probe ok', lalu akhiri dengan submit_task berisi ringkasan perubahan. Jangan menulis code di teks chat.";
        $started = now();
        $this->info('Model: '.$model.' | Peran prompt: '.$role);

        try {
            $startedAt = microtime(true);
            $result = $runner->runWithModel($role, $model, $dir, $prompt, true, [
                'source' => 'probe',
                'probe_token' => $token,
            ]);
            $seconds = round(microtime(true) - $startedAt, 1);
            $rounds = AgentToolRound::query()->where('probe_token', $token)->orderBy('round')->get();
            $this->info('HASIL: BERHASIL dalam '.$seconds.' detik, '.$rounds->count().' putaran.');
            foreach ($rounds as $round) {
                $names = collect($round->tool_calls ?? [])->map(fn ($call) => $call['name'] ?? '?')->implode(', ');
                $this->line('  Putaran '.$round->round.' ['.$round->model.']: '.($names !== '' ? $names : '(tanpa tool call)'));
            }
            $this->line('  File: '.implode(', ', $result['files']));
            $this->line('  Ringkasan: '.$result['summary']);
            $probeContent = File::exists($dir.'/PROBE.md') ? File::get($dir.'/PROBE.md') : null;
            $this->line('  Verifikasi PROBE.md: '.($probeContent === "probe ok\n" || $probeContent === 'probe ok' ? 'OK' : 'ISI TIDAK SESUAI / TIDAK ADA'));
        } catch (Throwable $exception) {
            $rounds = AgentToolRound::query()->where('probe_token', $token)->orderBy('round')->get();
            $this->error('HASIL: GAGAL — '.$exception->getMessage());
            $this->line('  Putaran tercatat: '.$rounds->count());
            foreach ($rounds as $round) {
                $names = collect($round->tool_calls ?? [])->map(fn ($call) => $call['name'] ?? '?')->implode(', ');
                $this->line('  Putaran '.$round->round.' ['.$round->model.']: '.($names !== '' ? $names : '(tanpa tool call)'));
            }
            $this->line('  (Putaran lengkap tersimpan di agent_tool_rounds, probe_token '.$token.')');
            File::deleteDirectory($dir);

            return self::FAILURE;
        }

        $usage = AiUsageRecord::query()
            ->where('source', 'probe')
            ->where('created_at', '>=', $started)
            ->sum('total_tokens');
        $this->line('  Token tercatat (probe, jendela ini): '.$usage);
        File::deleteDirectory($dir);

        return self::SUCCESS;
    }
}
