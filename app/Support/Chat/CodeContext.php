<?php

namespace App\Support\Chat;

use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Potongan kode project yang relevan untuk Scrum Master (HANYA BACA).
 *
 * Dibaca dari cabang utama (main) repository project lewat git (ls-tree, grep,
 * show), tanpa checkout, sehingga tidak mengganggu pekerjaan tim yang sedang
 * berjalan di folder kerja. Dipakai saat SM menjawab pertanyaan di tab Laporan
 * dan saat merumuskan perintah di tab Tanya, supaya jawaban dan kriterianya
 * berdasarkan kode nyata, bukan tebakan.
 */
class CodeContext
{
    /** Folder yang dibaca SM (kode aplikasi, bukan dependensi atau hasil build). */
    private const ROOTS = ['app/', 'resources/views/', 'routes/', 'database/migrations/', 'database/seeders/', 'config/'];

    /** File yang tidak pernah dikirim (rahasia atau tidak berguna). */
    private const SKIP = '/(^|\/)\.env|\.(pem|key|crt|p12|lock|min\.js|map|svg|png|jpe?g|gif|ico|woff2?)$|(^|\/)(vendor|node_modules|storage|public\/build)\//i';

    private const STOPWORDS = [
        'yang', 'dan', 'atau', 'pada', 'untuk', 'dengan', 'dari', 'kepada', 'tidak', 'belum', 'sudah', 'masih', 'agar', 'supaya',
        'saya', 'kamu', 'anda', 'kita', 'kami', 'ini', 'itu', 'tersebut', 'adalah', 'akan', 'bisa', 'dapat', 'harus', 'tolong',
        'mohon', 'bagaimana', 'kenapa', 'mengapa', 'apakah', 'apa', 'mana', 'dimana', 'kapan', 'jika', 'kalau', 'ketika', 'saat',
        'halaman', 'tampil', 'tampilan', 'menampilkan', 'muncul', 'masuk', 'buat', 'membuat', 'tambah', 'tambahkan', 'ubah', 'ubahlah',
        'perbaiki', 'periksa', 'cek', 'lagi', 'juga', 'sebagai', 'secara', 'lebih', 'sangat', 'semua', 'setiap', 'data', 'sistem',
        'when', 'then', 'given', 'the', 'and', 'with', 'that', 'this', 'task', 'fix', 'description', 'acceptance', 'criteria',
    ];

    public static function for(?Project $project, string $question, int $budget = 12000): string
    {
        $repo = rtrim((string) $project?->workspace_path, '/');
        if ($repo === '' || ! is_dir($repo.'/.git') && ! is_file($repo.'/.git')) {
            return '';
        }

        try {
            $ref = self::ref($repo);
            $files = array_values(array_filter(
                explode("\n", self::git($repo, ['ls-tree', '-r', '--name-only', $ref])),
                fn ($f) => $f !== '' && self::readable($f)
            ));
            if ($files === []) {
                return '';
            }

            $keywords = self::keywords($question);
            if ($keywords === []) {
                return '';
            }

            // Skor: kata kunci di nama/path file (bobot 4) + jumlah kemunculan di isi (maks 6 per kata).
            $scores = [];
            foreach ($files as $file) {
                $path = mb_strtolower($file);
                foreach ($keywords as $keyword) {
                    if (str_contains($path, $keyword)) {
                        $scores[$file] = ($scores[$file] ?? 0) + 4;
                    }
                }
            }
            foreach ($keywords as $keyword) {
                $out = self::git($repo, ['grep', '-c', '-i', '-I', '-F', '-e', $keyword, $ref, '--', ...self::ROOTS], true);
                foreach (explode("\n", $out) as $line) {
                    if (! preg_match('/^'.preg_quote($ref, '/').':(.+):(\d+)$/', $line, $m) || ! self::readable($m[1])) {
                        continue;
                    }
                    $scores[$m[1]] = ($scores[$m[1]] ?? 0) + min(6, (int) $m[2]);
                }
            }
            if ($scores === []) {
                return '';
            }
            arsort($scores);

            $parts = [];
            $used = 0;
            foreach (array_slice(array_keys($scores), 0, 8) as $file) {
                if ($used >= $budget) {
                    break;
                }
                $content = self::git($repo, ['show', $ref.':'.$file]);
                $snippet = mb_strlen($content) <= 4000 ? $content : self::excerpt($content, $keywords, 3000);
                $snippet = Str::limit($snippet, max(500, $budget - $used), "\n…");
                $parts[] = '### '.$file."\n```\n".rtrim($snippet)."\n```";
                $used += mb_strlen($snippet);
            }

            return "KODE PROJECT YANG RELEVAN (dibaca dari cabang {$ref}; kamu HANYA membaca, tidak bisa mengubah kode).\n"
                .'Saat menjawab tentang kode, sebut nama file (dan nama method/baris bila perlu). Bila jawabannya butuh perubahan kode, sarankan PO memberi perintah di tab "Tanya Scrum Master".'
                ."\n\n".implode("\n\n", $parts);
        } catch (Throwable $exception) {
            Log::info('Konteks kode untuk SM tidak tersedia', ['project' => $project?->id, 'error' => $exception->getMessage()]);

            return '';
        }
    }

    /** @return array<int, string> */
    public static function keywords(string $text): array
    {
        $text = mb_strtolower($text);
        $words = preg_split('/[^a-z0-9_]+/u', $text) ?: [];
        $counts = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 4 || in_array($word, self::STOPWORDS, true) || ctype_digit($word)) {
                continue;
            }
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }
        arsort($counts);

        return array_slice(array_keys($counts), 0, 8);
    }

    private static function readable(string $file): bool
    {
        if (preg_match(self::SKIP, $file) === 1) {
            return false;
        }
        foreach (self::ROOTS as $root) {
            if (str_starts_with($file, $root)) {
                return true;
            }
        }

        return false;
    }

    /** Potongan sekitar baris yang memuat kata kunci (±10 baris). */
    private static function excerpt(string $content, array $keywords, int $limit): string
    {
        $lines = explode("\n", $content);
        $keep = [];
        foreach ($lines as $i => $line) {
            $lower = mb_strtolower($line);
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    for ($j = max(0, $i - 10); $j <= min(count($lines) - 1, $i + 10); $j++) {
                        $keep[$j] = true;
                    }
                    break;
                }
            }
        }
        ksort($keep);
        $out = '';
        $previous = -2;
        foreach (array_keys($keep) as $i) {
            if ($i !== $previous + 1) {
                $out .= "…\n";
            }
            $out .= ($i + 1).': '.$lines[$i]."\n";
            $previous = $i;
            if (mb_strlen($out) > $limit) {
                break;
            }
        }

        return $out !== '' ? $out : Str::limit($content, $limit);
    }

    private static function ref(string $repo): string
    {
        foreach (['main', 'master'] as $branch) {
            if (trim(self::git($repo, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch], true)) !== '') {
                return $branch;
            }
        }

        return 'HEAD';
    }

    /** @param  array<int, string>  $args */
    private static function git(string $repo, array $args, bool $allowFailure = false): string
    {
        $process = new Process(['git', '-c', 'credential.helper=', '-C', $repo, ...$args], null, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(15);
        $process->run();
        if (! $process->isSuccessful() && ! $allowFailure) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'git gagal');
        }

        return $process->getOutput();
    }
}
