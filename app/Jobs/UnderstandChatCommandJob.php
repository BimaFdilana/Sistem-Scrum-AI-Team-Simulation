<?php

namespace App\Jobs;

use App\Models\ChatCommand;
use App\Services\HermesClient;
use App\Support\Agents\AgentSoul;
use App\Support\Chat\CodeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scrum Master memahami perintah PO sebelum tim bekerja: merumuskan judul dan
 * 1-7 kriteria yang bisa dicek, atau memecah perintah besar menjadi 2-4 bagian.
 * Hasilnya ditampilkan di chat untuk dikonfirmasi PO.
 *
 * Dulu setiap BARIS perintah dianggap satu kriteria, sehingga perintah yang
 * ditulis rapi (Given/When/Then, Description, Task 1..) selalu gagal di breakdown
 * ("12 butir hanya dipetakan 4"), dan "Jalankan ulang" mengulang kegagalan yang sama.
 */
class UnderstandChatCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $commandId) {}

    public function handle(HermesClient $scrumMaster): void
    {
        $command = ChatCommand::query()->with(['conversation', 'project', 'sprint'])->find($this->commandId);
        if ($command === null || $command->phase !== ChatCommand::UNDERSTANDING || $command->conversation === null) {
            return;
        }

        try {
            $raw = $scrumMaster->sendMessage($this->prompt($command), 'chat-command-understand-'.$command->id.'-'.Str::random(6));
            $plan = self::parse($raw);
        } catch (Throwable $exception) {
            Log::warning('SM gagal memahami perintah chat', ['command' => $command->id, 'error' => $exception->getMessage()]);
            $plan = null;
        }

        if ($plan === null) {
            $command->forceFill(['phase' => ChatCommand::NOT_UNDERSTOOD])->save();
            $this->post($command, 'Saya belum berhasil merumuskan perintah ini menjadi pekerjaan yang jelas. Klik **Coba rumuskan lagi**, atau kirim perintah dengan menyebut halaman dan hasil yang Anda harapkan.');

            return;
        }

        DB::transaction(function () use ($command, $plan): void {
            if ($plan['parts'] === []) {
                $command->forceFill(['phase' => ChatCommand::AWAITING, 'title' => $plan['title'], 'criteria' => $plan['criteria']])->save();
                $this->post($command, 'Saya pahami perintah Anda sebagai berikut. Periksa, ubah bila perlu, lalu klik **Sesuai, kerjakan**.');

                return;
            }

            // Perintah besar: dipecah menjadi beberapa bagian yang dikerjakan berurutan.
            $command->forceFill(['phase' => ChatCommand::CANCELLED, 'title' => $plan['title'], 'criteria' => $plan['criteria']])->save();
            $this->post($command, 'Perintah ini terlalu besar untuk satu putaran kerja, jadi saya pecah menjadi '.count($plan['parts']).' bagian. '
                .'Kerjakan satu per satu: konfirmasi bagian 1, putuskan hasilnya, lalu lanjut ke bagian berikutnya.', false);
            foreach ($plan['parts'] as $i => $part) {
                $child = ChatCommand::query()->create([
                    'user_id' => $command->user_id,
                    'chat_conversation_id' => $command->chat_conversation_id,
                    'sprint_id' => $command->sprint_id,
                    'project_id' => $command->project_id,
                    'command' => $command->command,
                    'phase' => ChatCommand::AWAITING,
                    'title' => $part['title'],
                    'criteria' => $part['criteria'],
                    'parent_id' => $command->id,
                    'part_index' => $i + 1,
                    'part_total' => count($plan['parts']),
                ]);
                $this->post($child, null);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $command = ChatCommand::query()->find($this->commandId);
        if ($command !== null && $command->phase === ChatCommand::UNDERSTANDING) {
            $command->forceFill(['phase' => ChatCommand::NOT_UNDERSTOOD])->save();
            $this->post($command, 'Proses memahami perintah terhenti. Klik **Coba rumuskan lagi**.');
        }
    }

    private function prompt(ChatCommand $command): string
    {
        $context = [];
        if ($command->project) {
            $context[] = 'Project: '.$command->project->name.($command->project->description ? ' — '.$command->project->description : '');
        }
        if ($command->sprint) {
            $context[] = 'Sprint: '.$command->sprint->name.($command->sprint->goal ? ' (goal: '.$command->sprint->goal.')' : '');
        }

        return implode("\n\n", array_filter([
            AgentSoul::promptLine('scrum-master'),
            'Sebagai Scrum Master, rumuskan PERINTAH PO berikut menjadi pekerjaan yang jelas untuk tim (Backend Developer, Frontend Developer, QA). Jangan mengerjakannya, hanya rumuskan.',
            'Balas HANYA JSON: {"title":"judul singkat berawalan kata kerja, maks 100 karakter","criteria":["kriteria 1","kriteria 2"],"parts":[]}.',
            'Aturan criteria: 1-7 butir; tiap butir SATU kalimat yang hasilnya bisa dicek PO di aplikasi (halaman, data, atau tampilan yang terlihat); gabungkan Given/When/Then, deskripsi, dan daftar task menjadi kriteria hasil, jangan menyalin per baris; jangan menulis kriteria berupa langkah teknis seperti "periksa query".',
            'Aturan parts: isi HANYA bila pekerjaan terlalu besar untuk satu putaran (lebih dari 6 perubahan kode atau lebih dari 3 halaman/fitur berbeda). Bila diisi: 2-4 bagian berurutan, masing-masing {"title":"...","criteria":["..."]} dengan aturan yang sama; bagian berikutnya boleh memakai hasil bagian sebelumnya.',
            $command->hint ? "Percobaan sebelumnya gagal, hindari penyebabnya:\n".$command->hint : null,
            // Kode nyata project: kriteria merujuk halaman, data, dan nama yang benar-benar ada.
            CodeContext::for($command->project, $command->command, 9000),
            implode("\n", $context),
            "PERINTAH PO:\n".trim($command->command),
        ]));
    }

    /**
     * @return array{title: string, criteria: array<int, string>, parts: array<int, array{title: string, criteria: array<int, string>}>}|null
     */
    public static function parse(string $raw): ?array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            return null;
        }

        $clean = function ($criteria): array {
            return array_values(array_slice(array_filter(array_map(
                fn ($c) => is_string($c) ? Str::limit(trim(preg_replace('/\s+/', ' ', $c)), 400, '') : '',
                is_array($criteria) ? $criteria : []
            ), fn ($c) => $c !== ''), 0, 7));
        };

        $title = is_string($data['title'] ?? null) ? Str::limit(trim($data['title']), 150, '') : '';
        $criteria = $clean($data['criteria'] ?? []);
        $parts = [];
        foreach (array_slice(is_array($data['parts'] ?? null) ? $data['parts'] : [], 0, 4) as $part) {
            $partTitle = is_array($part) && is_string($part['title'] ?? null) ? Str::limit(trim($part['title']), 150, '') : '';
            $partCriteria = is_array($part) ? $clean($part['criteria'] ?? []) : [];
            if ($partTitle !== '' && $partCriteria !== []) {
                $parts[] = ['title' => $partTitle, 'criteria' => $partCriteria];
            }
        }
        if (count($parts) === 1) {
            [$title, $criteria, $parts] = [$parts[0]['title'], $parts[0]['criteria'], []];
        }

        if ($title === '' || ($criteria === [] && $parts === [])) {
            return null;
        }

        return ['title' => $title, 'criteria' => $criteria !== [] ? $criteria : $parts[0]['criteria'], 'parts' => $parts];
    }

    /** Kartu rencana di chat (null = kartu tanpa pengantar, mis. tiap bagian). */
    private function post(ChatCommand $command, ?string $text, bool $card = true): void
    {
        $command->conversation?->messages()->create([
            'role' => 'assistant',
            'kind' => $card ? 'command_plan' : 'report',
            'content' => $text ?? ($command->partLabel() ?? 'Rencana perintah'),
            'chat_command_id' => $command->id,
        ]);
        $command->conversation?->touch();
    }
}
