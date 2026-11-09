<?php

namespace App\Support\Demo;

use App\Models\DemoServer;
use App\Models\Project;
use App\Services\GithubApiClient;
use App\Services\GithubProjectService;
use App\Support\Projects\CliPhp;
use App\Support\Projects\ProjectEnvironment;
use App\Support\Projects\ProjectProfiler;
use App\Support\Projects\ProjectProvisioner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Menjalankan demo sementara project (php -S) di port unik per project.
 */
class DemoServerManager
{
    public function runningFor(Project $project): ?DemoServer
    {
        $demo = DemoServer::query()->where('project_id', $project->id)
            ->where('status', 'RUNNING')->latest()->first();
        if (! $demo) {
            return null;
        }
        if ($demo->expires_at?->isPast() || ! $demo->isAlive()) {
            $this->stop($demo, 'EXPIRED');

            return null;
        }

        return $demo;
    }

    /**
     * Segarkan demo yang SEDANG berjalan setelah kode project berubah (story diterima):
     * pasang dependensi bila perlu, jalankan migrasi database demo, dan bangun ulang aset
     * yang usang. Server demo membaca file langsung dari baseline sehingga tidak perlu restart.
     * Tanpa demo yang berjalan, tidak ada yang dilakukan (start() akan menyiapkan semuanya).
     */
    public function refresh(Project $project): void
    {
        $demo = $this->runningFor($project);
        if ($demo === null) {
            return;
        }

        $baseRoot = $demo->worktree_path ? rtrim($demo->worktree_path, '/') : rtrim((string) $project->workspace_path, '/');
        $profile = $project->profile;
        $projectRoot = $profile?->subdir ? $baseRoot.'/'.$profile->subdir : $baseRoot;
        $log = storage_path('logs/demo-project-'.$project->id.'.log');

        app(ProjectProvisioner::class)->ensure($project);
        $this->demoEnvironment($projectRoot, $project, $log);
        $this->ensureFrontendBuild($projectRoot, $log);
    }

    public function start(Project $project): DemoServer
    {
        if ($this->runningFor($project)) {
            throw new RuntimeException('Demo project ini sudah berjalan.');
        }

        // Fase 3 GitHub: bila project terhubung GitHub dan branch integration ada,
        // demo jalan dari kode integration terbaru (worktree terpisah).
        // Bila tidak, perilaku lama: dari baseline lokal.
        $worktreePath = $this->prepareIntegrationWorktree($project);
        $baseRoot = $worktreePath !== null ? rtrim($worktreePath, '/') : rtrim((string) $project->workspace_path, '/');

        // Profil runtime: versi PHP project dan letak Laravel (monorepo).
        $profile = $project->profile;
        if ($profile === null) {
            try {
                $profile = app(ProjectProfiler::class)->profile($project);
            } catch (\Throwable) {
                $profile = null;
            }
        }
        $projectRoot = $profile?->subdir ? $baseRoot.'/'.$profile->subdir : $baseRoot;
        $docroot = $projectRoot.'/public';

        if (! is_dir($docroot)) {
            // Bersihkan worktree bila docroot tidak valid.
            if ($worktreePath !== null) {
                $this->removeWorktree($project, $worktreePath);
            }
            throw new RuntimeException('Baseline project belum siap untuk demo.');
        }

        // Siapkan dependency/.env/migrasi sebelum server dijalankan. Tanpa
        // langkah ini project hasil clone tanpa vendor/ selalu berakhir 500.
        try {
            app(ProjectProvisioner::class)->ensure($project);
        } catch (\Throwable $exception) {
            if ($worktreePath !== null) {
                $this->removeWorktree($project, $worktreePath);
            }
            throw new RuntimeException('Demo tidak bisa disiapkan: '.mb_substr($exception->getMessage(), 0, 300));
        }

        $phpBinary = $profile?->php_binary ?: CliPhp::path();
        $port = $this->allocatePort();
        $log = storage_path('logs/demo-project-'.$project->id.'.log');

        // Database demo memakai SQLite lokal: host demo tidak menyediakan
        // MySQL, sementara project PO sering dikonfigurasi DB_CONNECTION=mysql.
        // Env proses MENANG atas .env karena Dotenv bersifat immutable.
        $demoEnv = $this->demoEnvironment($projectRoot, $project, $log);

        // Akun masuk demo: kata sandi acak untuk akun di database demo (bukan database project).
        $credentials = app(DemoAccounts::class)->provision((string) ($demoEnv['DB_DATABASE'] ?? ''));

        // Aset frontend: tanpa manifest Vite, halaman ber-@vite (login, dsb.)
        // melempar 500 "Vite manifest not found". Bangun bila memungkinkan,
        // dan bila gagal sediakan manifest stub agar halaman tetap terbuka.
        $this->ensureFrontendBuild($projectRoot, $log);

        // Server dijalankan terpisah penuh: setsid + ketiga fd dialihkan,
        // lalu bash keluar. Versi sebelumnya (proc_open lalu dibiarkan)
        // membuat proses PHP pemanggil MENUNGGU anak saat shutdown — inilah
        // penyebab "Jalankan demo" menggantung walau server sudah siap.
        $envPrefix = '';
        foreach ($demoEnv as $key => $value) {
            $envPrefix .= ' '.escapeshellarg($key.'='.$value);
        }

        $shell = 'setsid env'.$envPrefix.' '.escapeshellarg($phpBinary)
            .' -S 0.0.0.0:'.$port.' -t '.escapeshellarg($docroot)
            .' </dev/null > '.escapeshellarg($log).' 2>&1 & echo $!';

        $launcher = new Process(['bash', '-lc', $shell], $projectRoot, ProjectEnvironment::scrubbed());
        $launcher->setTimeout(20);
        $launcher->run();
        $pid = (int) trim($launcher->getOutput());

        if ($pid <= 0) {
            if ($worktreePath !== null) {
                $this->removeWorktree($project, $worktreePath);
            }
            throw new RuntimeException('Gagal menjalankan server demo.');
        }

        // Jangan klaim RUNNING hanya karena proses hidup: pastikan endpoint
        // benar-benar menjawab (dulu demo "RUNNING" padahal HTTP 500 karena
        // vendor/DB tidak siap).
        $healthError = $this->waitForHealthy($port, $pid, $log);
        if ($healthError !== null) {
            @posix_kill($pid, 9);
            if ($worktreePath !== null) {
                $this->removeWorktree($project, $worktreePath);
            }

            throw new RuntimeException($healthError);
        }

        return DemoServer::query()->create([
            'project_id' => $project->id,
            'port' => $port,
            'pid' => $pid,
            'status' => 'RUNNING',
            'started_at' => now(),
            'expires_at' => now()->addMinutes((int) config('demo.ttl_minutes', 60)),
            'worktree_path' => $worktreePath,
            'credentials' => $credentials,
        ]);
    }

    /**
     * Env proses demo: paksa SQLite lokal + driver file agar tidak butuh
     * MySQL/Redis di host demo. Migrasi dijalankan sekali saat DB masih kosong.
     *
     * @return array<string, string>
     */
    protected function demoEnvironment(string $projectRoot, Project $project, string $log): array
    {
        $dir = storage_path('app/demo-db');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $database = $dir.'/project-'.$project->id.'.sqlite';
        if (! file_exists($database)) {
            @touch($database);
        }

        $env = [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_FOREIGN_KEYS' => 'true',
            'SESSION_DRIVER' => 'file',
            'CACHE_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'APP_ENV' => 'local',
        ];

        // Migrasi dijalankan setiap start: DB demo lama bisa tertinggal
        // ketika task menambah migrasi baru (demo menampilkan skema usang).
        // Saat sudah mutakhir, perintah ini hanya beberapa detik.
        $fresh = filesize($database) === 0;
        $this->migrateDemoDatabase($projectRoot, $database, $env, $log);
        if ($fresh) {
            $this->seedDemoDatabase($projectRoot, $env, $log);
        }

        return $env;
    }

    /**
     * Bangun ulang aset frontend project (dipakai tombol di UI setelah task FE).
     *
     * @return array{ok: bool, message: string}
     */
    public function rebuildAssets(Project $project): array
    {
        $root = rtrim((string) $project->workspace_path, '/');
        if ($root === '' || ! is_dir($root)) {
            return ['ok' => false, 'message' => 'Baseline project belum ada.'];
        }

        $manifest = $root.'/public/build/manifest.json';
        $log = storage_path('logs/demo-project-'.$project->id.'.log');

        // JANGAN hapus manifest lebih dulu: demo yang sedang melayani request
        // akan 500 "Vite manifest not found" selama proses build. Simpan
        // cadangan, bangun ulang, dan pulihkan bila build gagal.
        $backup = $manifest.'.bak';
        if (is_file($manifest)) {
            @copy($manifest, $backup);
        }

        $this->ensureFrontendBuild($root, $log, true);

        if (! is_file($manifest) && is_file($backup)) {
            @copy($backup, $manifest);
        }
        @unlink($backup);

        if (is_file($manifest)) {
            $entries = count((array) json_decode((string) @file_get_contents($manifest), true));
            $stub = str_contains((string) @file_get_contents($manifest), 'stub-');

            return [
                'ok' => true,
                'message' => ($stub ? 'Aset dibangun dengan manifest stub ('.$entries.' entri).' : 'Aset berhasil dibangun ('.$entries.' entri).'),
            ];
        }

        return ['ok' => false, 'message' => 'Aset gagal dibangun; periksa log demo project.'];
    }

    /**
     * Aset hasil build sudah usang bila ada sumber frontend yang lebih baru dari manifest.
     * Tailwind memindai file view, jadi view baru memakai kelas yang belum ada di CSS lama;
     * dulu build hanya berjalan sekali (saat manifest belum ada) sehingga hasil kerja baru
     * tampil tanpa gaya atau tidak terlihat di demo.
     */
    public static function assetsAreStale(string $projectRoot): bool
    {
        $manifest = $projectRoot.'/public/build/manifest.json';
        if (! is_file($manifest)) {
            return true;
        }
        $builtAt = filemtime($manifest);

        foreach (['tailwind.config.js', 'vite.config.js', 'postcss.config.js', 'package.json'] as $file) {
            if (is_file($projectRoot.'/'.$file) && filemtime($projectRoot.'/'.$file) > $builtAt) {
                return true;
            }
        }
        foreach (['resources/css', 'resources/js', 'resources/views'] as $dir) {
            $path = $projectRoot.'/'.$dir;
            if (! is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getMTime() > $builtAt) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Pastikan public/build/manifest.json tersedia dan mutakhir untuk demo.
     */
    public function ensureFrontendBuild(string $projectRoot, string $log, bool $force = false): void
    {
        $manifest = $projectRoot.'/public/build/manifest.json';
        if (is_file($manifest) && ! $force && ! self::assetsAreStale($projectRoot)) {
            return;
        }

        $hasPackage = is_file($projectRoot.'/package.json');
        $node = trim((string) @shell_exec('command -v node 2>/dev/null'));

        if ($hasPackage && $node !== '') {
            // www-data punya HOME /var/www yang tidak bisa ditulis, sehingga
            // npm gagal menyimpan cache. Arahkan HOME + cache ke storage.
            $npmHome = storage_path('app/private/.npm-home');
            @mkdir($npmHome.'/.npm', 0775, true);
            $env = ProjectEnvironment::scrubbed();
            $env['PATH'] = (getenv('PATH') ?: '/usr/bin').':/usr/local/bin';
            $env['HOME'] = $npmHome;
            $env['npm_config_cache'] = $npmHome.'/.npm';

            if (! is_dir($projectRoot.'/node_modules')) {
                // `npm install` menulis ulang package-lock.json (mis. field name menjadi nama
                // folder "baseline"), sehingga baseline menjadi "tidak bersih" dan semua
                // eksekusi mode langsung berikutnya ditolak. Pakai `npm ci` (tidak menulis
                // lockfile); tanpa lockfile atau bila tidak sinkron, `--no-package-lock`.
                $command = is_file($projectRoot.'/package-lock.json')
                    ? ['npm', 'ci', '--no-audit', '--no-fund']
                    : ['npm', 'install', '--no-audit', '--no-fund', '--no-package-lock'];
                $install = new Process($command, $projectRoot, $env);
                $install->setTimeout(900);
                $install->run();
                if (! $install->isSuccessful() && $command[1] === 'ci') {
                    $install = new Process(['npm', 'install', '--no-audit', '--no-fund', '--no-package-lock'], $projectRoot, $env);
                    $install->setTimeout(900);
                    $install->run();
                }
                @file_put_contents($log, "\n[npm ".$command[1].'] exit='.$install->getExitCode()."\n".mb_substr($install->getErrorOutput(), -800)."\n", FILE_APPEND);
            }

            if (is_dir($projectRoot.'/node_modules')) {
                $build = new Process(['npm', 'run', 'build'], $projectRoot, $env);
                $build->setTimeout(900);
                $build->run();
                @file_put_contents($log, "\n[vite build] exit=".$build->getExitCode()."\n".mb_substr($build->getErrorOutput(), -800)."\n", FILE_APPEND);
            }
        }

        if (is_file($manifest)) {
            return;
        }

        // Stub: halaman tetap render walau aset belum dibangun.
        $views = glob($projectRoot.'/resources/views/**/*.blade.php') ?: [];
        $views = array_merge($views, glob($projectRoot.'/resources/views/*.blade.php') ?: []);
        $entries = [];
        foreach ($views as $view) {
            $content = (string) @file_get_contents($view);
            if (preg_match_all('/@vite\(\s*\[([^\]]*)\]/', $content, $m)) {
                foreach ($m[1] as $list) {
                    foreach (preg_split('/[,\s]+/', $list) as $entry) {
                        $entry = trim($entry, " \t\n\r\0\x0B'\"");
                        if ($entry !== '' && str_contains($entry, '/')) {
                            $entries[$entry] = true;
                        }
                    }
                }
            }
        }

        $assets = $projectRoot.'/public/build/assets';
        @mkdir($assets, 0775, true);
        $stub = [];
        foreach (array_keys($entries) as $entry) {
            $ext = pathinfo($entry, PATHINFO_EXTENSION) ?: 'js';
            $name = 'stub-'.substr(md5($entry), 0, 10).'.'.$ext;
            if (! is_file($assets.'/'.$name)) {
                @file_put_contents($assets.'/'.$name, $ext === 'css' ? '/* stub */' : '// stub');
            }
            $stub[$entry] = ['file' => 'assets/'.$name, 'src' => $entry, 'isEntry' => true];
        }

        if ($stub === []) {
            $stub['resources/js/app.js'] = ['file' => 'assets/stub-app.js', 'src' => 'resources/js/app.js', 'isEntry' => true];
            @file_put_contents($assets.'/stub-app.js', '// stub');
            @mkdir($projectRoot.'/public/build', 0775, true);
        }

        @mkdir($projectRoot.'/public/build', 0775, true);
        @file_put_contents($manifest, json_encode($stub, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @file_put_contents($log, "\n[manifest stub] ".count($stub)." entry\n", FILE_APPEND);
    }

    /**
     * Siapkan database demo lebih awal (saat import project), supaya kegagalan
     * migrasi diketahui sebelum PO menekan "Jalankan demo".
     *
     * @return array{ok: bool, database: string, message: string}
     */
    public function prepareDatabase(Project $project): array
    {
        $root = rtrim((string) $project->workspace_path, '/');
        if ($root === '' || ! is_dir($root)) {
            return ['ok' => false, 'database' => '', 'message' => 'Baseline project belum ada.'];
        }

        $log = storage_path('logs/demo-project-'.$project->id.'.log');

        try {
            $env = $this->demoEnvironment($root, $project, $log);
            $database = (string) ($env['DB_DATABASE'] ?? '');

            return ['ok' => $database !== '' && is_file($database), 'database' => $database, 'message' => 'Database demo siap.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'database' => '', 'message' => 'Persiapan database demo gagal: '.mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /**
     * Migrasi SQLite untuk demo. Sebagian project PO memakai migrasi
     * MySQL-only (raw `MODIFY`); migrasi seperti itu ditandai sudah jalan
     * dan dilewati agar demo tetap bisa dibuka. Maksimum 10 putaran.
     *
     * @param  array<string, string>  $env
     */
    private function migrateDemoDatabase(string $projectRoot, string $database, array $env, string $log): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $process = new Process(
                [CliPhp::path(), 'artisan', 'migrate', '--force', '--no-interaction'],
                $projectRoot,
                array_merge(ProjectEnvironment::scrubbed(), $env),
                null,
                300
            );
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();

            file_put_contents(
                $log,
                '['.date('c')."] migrate #{$attempt}: exit=".$process->getExitCode()."\n".mb_substr($output, -4000)."\n",
                FILE_APPEND
            );

            if ($process->isSuccessful()) {
                return;
            }

            if (! preg_match_all('/^Migrating:\s*(.+)$/m', $output, $matches) || $matches[1] === []) {
                throw new RuntimeException('Demo tidak bisa disiapkan: migrasi SQLite gagal tanpa nama migrasi (lihat '.$log.').');
            }

            $failing = trim((string) end($matches[1]));

            // Hanya lewati migrasi yang jelas MySQL-only (MODIFY/CHANGE/ENGINE).
            if (! preg_match('/MODIFY|CHANGE\s+COLUMN|ENGINE\s*=/i', $output)) {
                throw new RuntimeException('Demo tidak bisa disiapkan: migrasi "'.$failing.'" gagal di SQLite (lihat '.$log.').');
            }

            try {
                $pdo = new \PDO('sqlite:'.$database);
                $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch),0)+1 FROM migrations')->fetchColumn();
                $statement = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
                $statement->execute([$failing, $batch]);
            } catch (\Throwable $exception) {
                throw new RuntimeException('Demo tidak bisa disiapkan: gagal menandai migrasi MySQL-only ('.$exception->getMessage().').');
            }

            file_put_contents(
                $log,
                '['.date('c')."] SKIP migrasi MySQL-only: {$failing}\n",
                FILE_APPEND
            );
        }

        throw new RuntimeException('Demo tidak bisa disiapkan: terlalu banyak migrasi MySQL-only dilewati (lihat '.$log.').');
    }

    /**
     * Isi data awal (setting/role/admin) agar halaman demo tidak error
     * karena tabel kosong. Gagal seeding TIDAK menggagalkan demo: sebagian
     * project memang tidak punya seeder.
     *
     * @param  array<string, string>  $env
     */
    private function seedDemoDatabase(string $projectRoot, array $env, string $log): void
    {
        $process = new Process(
            [CliPhp::path(), 'artisan', 'db:seed', '--force', '--no-interaction'],
            $projectRoot,
            array_merge(ProjectEnvironment::scrubbed(), $env),
            null,
            240
        );
        $process->run();

        file_put_contents(
            $log,
            '['.date('c').'] db:seed exit='.$process->getExitCode()."\n".mb_substr($process->getOutput().$process->getErrorOutput(), -2000)."\n",
            FILE_APPEND
        );
    }

    /**
     * Tunggu endpoint demo menjawab. Null = sehat; string = pesan kegagalan.
     * 2xx/3xx dianggap sehat (halaman login juga valid), 5xx/putus = gagal.
     */
    protected function waitForHealthy(int $port, int $pid, string $log): ?string
    {
        $url = 'http://127.0.0.1:'.$port.'/';

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            usleep(500_000);

            if (! $this->pidAlive($pid)) {
                return 'Server demo berhenti saat start. Log: '.$this->logTail($log);
            }

            try {
                $response = Http::timeout(5)->get($url);
                $status = $response->status();

                if ($status < 500) {
                    return null;
                }

                if ($attempt >= 4) {
                    return 'Demo menjawab HTTP '.$status.' — project gagal boot. Log: '.$this->logTail($log);
                }
            } catch (\Throwable) {
                // Belum siap (connection refused) — coba lagi.
            }
        }

        return 'Demo tidak menjawab di '.$url.' setelah 10 detik. Log: '.$this->logTail($log);
    }

    /** Ambil 2 baris terakhir log demo untuk pesan error. */
    private function logTail(string $log): string
    {
        if (! is_file($log)) {
            return '(log tidak ada)';
        }

        $lines = array_slice(array_filter(explode("\n", (string) @file_get_contents($log))), -2);

        return mb_substr(trim(implode(' | ', $lines)), 0, 300);
    }

    /**
     * Fase 3 GitHub: siapkan worktree dari branch integration terbaru.
     * Return path worktree, atau null bila GitHub tidak dipakai/tidak ada integration.
     */
    protected function prepareIntegrationWorktree(Project $project): ?string
    {
        try {
            if (! filled($project->github_owner) || ! filled($project->github_repo)) {
                return null;
            }

            $service = app(GithubProjectService::class);
            if (! $service->isConnected()) {
                return null;
            }

            $github = app(GithubApiClient::class);
            if ($github->branchSha($project->github_owner, $project->github_repo, 'integration') === null) {
                return null; // Belum ada integration — pakai baseline lokal.
            }

            $workspace = (string) $project->workspace_path;
            if ($workspace === '' || ! is_dir($workspace)) {
                return null;
            }

            // Sinkronkan branch integration lokal dengan remote.
            $service->syncBranchFromRemote($workspace, 'integration');

            // Buat worktree terpisah agar workspace utama tidak terganggu.
            $worktreePath = storage_path('app/demo-worktrees/'.$project->id);
            $this->removeWorktree($project, $worktreePath); // Bersihkan sisa lama.
            $this->git($workspace, ['worktree', 'add', '--detach', $worktreePath, 'integration']);

            return $worktreePath;
        } catch (\Throwable $e) {
            Log::warning('Demo worktree integration gagal, pakai baseline', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Hapus worktree demo (best-effort).
     */
    protected function removeWorktree(Project $project, string $worktreePath): void
    {
        try {
            $workspace = (string) $project->workspace_path;
            if ($workspace !== '' && is_dir($workspace) && is_dir($worktreePath)) {
                $this->git($workspace, ['worktree', 'remove', '--force', $worktreePath]);
            }
        } catch (\Throwable) {
            // Abaikan — best effort.
        }
        if (is_dir($worktreePath)) {
            @rmdir($worktreePath);
        }
    }

    /**
     * Git via proc_open tanpa shell (pola sama seperti GithubProjectService).
     */
    protected function git(string $cwd, array $args): string
    {
        $command = array_merge(['git', '-c', 'credential.helper='], $args);
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, $cwd, ['GIT_TERMINAL_PROMPT' => '0']);
        if (! is_resource($process)) {
            throw new RuntimeException('Git tidak dapat dijalankan.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Perintah git demo gagal.');
        }

        return $stdout;
    }

    /**
     * Periksa kesehatan demo dan simpan buktinya.
     *
     * Bila demo belum berjalan, demo dinyalakan lebih dulu (kecuali
     * $startIfMissing = false). Status OK hanya diberikan kalau endpoint
     * menjawab < 500 DAN tidak mengandung penanda halaman error — sebelumnya
     * halaman login yang 200 dianggap "sehat" padahal fiturnya rusak.
     *
     * @return array{ok: bool, http: ?int, url: ?string, error: ?string, demo: ?DemoServer}
     */
    public function verify(Project $project, bool $startIfMissing = true): array
    {
        $demo = $this->runningFor($project);

        if ($demo === null) {
            if (! $startIfMissing) {
                return ['ok' => false, 'http' => null, 'url' => null, 'error' => 'Demo belum berjalan.', 'demo' => null];
            }

            try {
                $demo = $this->start($project);
            } catch (Throwable $exception) {
                return ['ok' => false, 'http' => null, 'url' => null, 'error' => $exception->getMessage(), 'demo' => null];
            }
        }

        $url = 'http://127.0.0.1:'.$demo->port.'/';
        $status = null;
        $error = null;

        try {
            $response = Http::timeout(10)->get($url);
            $status = $response->status();
            $body = (string) $response->body();

            $markers = ['Whoops', 'SQLSTATE', '<title>Upsss', 'Internal Server Error', 'Server Error'];

            if ($status >= 500) {
                $error = 'Demo menjawab HTTP '.$status;
            } else {
                foreach ($markers as $marker) {
                    if (stripos($body, $marker) !== false) {
                        $error = 'Halaman demo mengandung penanda error: '.$marker;
                        break;
                    }
                }
            }
        } catch (Throwable $exception) {
            $error = 'Demo tidak bisa dihubungi: '.$exception->getMessage();
        }

        $demo->forceFill([
            'health_status' => $error === null ? 'OK' : 'FAILED',
            'health_http_status' => $status,
            'health_error' => $error,
            'health_checked_at' => now(),
        ])->save();

        return [
            'ok' => $error === null,
            'http' => $status,
            'url' => $url,
            'error' => $error,
            'demo' => $demo,
        ];
    }

    public function stop(DemoServer $demo, string $status = 'STOPPED'): void
    {
        if ($demo->pid) {
            // Matikan seluruh process group (setsid membuat pid = pgid).
            @posix_kill(-$demo->pid, 15);
            if ($this->pidAlive($demo->pid)) {
                @posix_kill($demo->pid, 15);
            }
            sleep(1);
            @posix_kill(-$demo->pid, 9);
            if ($this->pidAlive($demo->pid)) {
                @posix_kill($demo->pid, 9);
            }
        }

        // Jaring pengaman: proses php -S yang masih memakai port demo.
        if ($demo->port) {
            $this->killByPort((int) $demo->port);
        }
        // Bersihkan worktree integration bila ada.
        if (filled($demo->worktree_path)) {
            $project = $demo->project;
            if ($project) {
                $this->removeWorktree($project, (string) $demo->worktree_path);
            }
        }
        $demo->forceFill(['status' => $status])->save();
    }

    /** Hentikan semua demo yang kedaluwarsa/mati. Return jumlah yang dihentikan. */
    public function prune(): int
    {
        $count = 0;
        foreach (DemoServer::query()->where('status', 'RUNNING')->get() as $demo) {
            if ($demo->expires_at?->isPast() || ! $demo->isAlive()) {
                $this->stop($demo, 'EXPIRED');
                $count++;
            }
        }

        // Proses php -S yatim (record hilang/berhenti) tetap memakan RAM.
        $count += $this->reapOrphans();

        return $count;
    }

    /** Port yang sedang dipakai demo berstatus RUNNING. */
    private function activePorts(): array
    {
        return DemoServer::query()->where('status', 'RUNNING')->pluck('port')->filter()->map(fn ($p) => (int) $p)->all();
    }

    /** Matikan proses php -S yang memakai port tertentu. */
    private function killByPort(int $port): void
    {
        $process = new Process(['pgrep', '-f', '-S 0.0.0.0:'.$port]);
        $process->run();
        foreach (preg_split('/\s+/', trim($process->getOutput())) as $pid) {
            if ($pid !== '' && (int) $pid > 0) {
                @posix_kill((int) $pid, 15);
            }
        }
        sleep(1);
        $again = new Process(['pgrep', '-f', '-S 0.0.0.0:'.$port]);
        $again->run();
        foreach (preg_split('/\s+/', trim($again->getOutput())) as $pid) {
            if ($pid !== '' && (int) $pid > 0) {
                @posix_kill((int) $pid, 9);
            }
        }
    }

    /** Buru proses demo yang tidak lagi punya record RUNNING. */
    public function reapOrphans(): int
    {
        $active = $this->activePorts();
        $process = new Process(['pgrep', '-af', 'php.*-S (0.0.0.0|127.0.0.1):']);
        $process->run();

        $killed = 0;
        foreach (preg_split('/\r?\n/', trim($process->getOutput())) as $line) {
            if ($line === '' || ! preg_match('/-S (?:0\.0\.0\.0|127\.0\.0\.1):(\d+)/', $line, $m)) {
                continue;
            }
            $pid = (int) strtok($line, ' ');
            $port = (int) $m[1];
            if ($pid <= 0 || in_array($port, $active, true)) {
                continue;
            }
            @posix_kill($pid, 15);
            usleep(300000);
            @posix_kill($pid, 9);
            $killed++;
        }

        return $killed;
    }

    public function url(DemoServer $demo, string $host): string
    {
        // Subdomain demo (nginx -> port demo) dipakai bila dikonfigurasi:
        // port 9110 tidak bisa dijangkau dari internet karena proxy hanya
        // meneruskan 80/443.
        $public = trim((string) config('demo.public_host', ''));
        if ($public !== '') {
            return rtrim($public, '/').'/';
        }

        $host = preg_replace('/:\d+$/', '', $host) ?: $host;

        return 'http://'.$host.':'.$demo->port;
    }

    private function allocatePort(): int
    {
        $from = (int) config('demo.port_from', 9110);
        $to = (int) config('demo.port_to', 9130);
        $used = DemoServer::query()->where('status', 'RUNNING')->pluck('port')->all();
        for ($port = $from; $port <= $to; $port++) {
            if (in_array($port, $used, true) || $this->portOpen($port)) {
                continue;
            }

            return $port;
        }
        throw new RuntimeException('Tidak ada port demo yang tersedia.');
    }

    private function portOpen(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);

        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }

        return false;
    }

    private function pidAlive(?int $pid): bool
    {
        return $pid && function_exists('posix_kill') && @posix_kill($pid, 0);
    }
}
