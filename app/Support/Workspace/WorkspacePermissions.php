<?php

namespace App\Support\Workspace;

/**
 * Izin akses folder project untuk dua user yang berbeda.
 *
 * Laravel (www-data) membuat worktree, tetapi worker Hermes berjalan sebagai
 * user lain (sidik). Tanpa ACL, worker gagal spawn dengan "Permission denied"
 * dan kartu kanban langsung mati — ini yang terjadi pada run pertama project
 * sipua (4 Nov 2026).
 */
class WorkspacePermissions
{
    /** User yang menjalankan worker Hermes. */
    private const WORKER_USER = 'sidik';

    /** User yang menjalankan Laravel. */
    private const APP_USER = 'www-data';

    /**
     * Beri kedua user akses rwx, termasuk untuk file/dir baru (default ACL).
     */
    public static function grant(string $path): void
    {
        if (! is_dir($path) || ! function_exists('shell_exec') || self::setfaclMissing()) {
            return;
        }

        $quoted = escapeshellarg($path);
        $users = self::WORKER_USER.','.self::APP_USER;

        shell_exec('setfacl -R -m u:'.self::WORKER_USER.':rwX,u:'.self::APP_USER.':rwX '.$quoted.' 2>/dev/null');
        shell_exec('find '.$quoted.' -type d -exec setfacl -d -m u:'.self::WORKER_USER.':rwX,u:'.self::APP_USER.':rwX {} + 2>/dev/null');
    }

    private static function setfaclMissing(): bool
    {
        return shell_exec('command -v setfacl 2>/dev/null') === null;
    }
}
