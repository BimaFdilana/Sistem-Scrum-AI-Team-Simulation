<?php

namespace App\Support\Demo;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Akun masuk ke website demo.
 *
 * Demo memakai database SQLite sendiri yang diisi db:seed milik project; kata sandi seeder
 * tersimpan ter-hash sehingga tidak diketahui ScrumSpace dan sering berupa tebakan umum
 * ("password"). Saat demo dinyalakan, akun di database DEMO itu (bukan project, bukan
 * database ScrumSpace) diberi satu kata sandi acak baru, lalu daftar akunnya ditampilkan
 * ke PO selama demo aktif.
 */
class DemoAccounts
{
    public const MAX_ACCOUNTS = 12;

    /** Kolom yang lazim menyimpan peran pada tabel users project. */
    private const ROLE_COLUMNS = ['role', 'peran', 'level', 'jabatan', 'tipe', 'type', 'user_type', 'role_name'];

    /**
     * @return array{password: ?string, accounts: array<int, array{name: string, email: string, role: ?string}>}
     */
    public function provision(string $databasePath): array
    {
        $empty = ['password' => null, 'accounts' => []];

        try {
            if (! is_file($databasePath) || filesize($databasePath) === 0) {
                return $empty;
            }

            $pdo = new PDO('sqlite:'.$databasePath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout = 5000');

            $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (! in_array('email', $columns, true) || ! in_array('password', $columns, true)) {
                return $empty;
            }

            $roleColumn = collect(self::ROLE_COLUMNS)->first(fn (string $column) => in_array($column, $columns, true));
            $select = 'id, email'.(in_array('name', $columns, true) ? ', name' : '').($roleColumn ? ', "'.$roleColumn.'" AS role' : '');
            $users = $pdo->query('SELECT '.$select.' FROM users ORDER BY id LIMIT '.self::MAX_ACCOUNTS)->fetchAll(PDO::FETCH_ASSOC);
            if ($users === []) {
                return $empty;
            }

            $password = Str::password(12, symbols: false);
            $hash = Hash::make($password);
            $verified = in_array('email_verified_at', $columns, true);

            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE users SET password = :password'.($verified ? ', email_verified_at = COALESCE(email_verified_at, :now)' : '').' WHERE id = :id');
            foreach ($users as $user) {
                $params = ['password' => $hash, 'id' => $user['id']];
                if ($verified) {
                    $params['now'] = now()->toDateTimeString();
                }
                $update->execute($params);
            }
            $pdo->commit();

            return [
                'password' => $password,
                'accounts' => array_map(fn (array $user) => [
                    'name' => (string) ($user['name'] ?? ''),
                    'email' => (string) $user['email'],
                    'role' => isset($user['role']) && $user['role'] !== '' ? (string) $user['role'] : null,
                ], $users),
            ];
        } catch (Throwable) {
            // Akun demo hanya kemudahan; kegagalan di sini tidak boleh menggagalkan demo.
            return $empty;
        }
    }
}
