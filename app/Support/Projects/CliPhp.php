<?php

namespace App\Support\Projects;

/**
 * Lokasi biner `php` CLI yang aman dipakai dari proses mana pun.
 *
 * PHP_BINARY di dalam PHP-FPM menunjuk ke `php-fpm`, bukan `php`. Menjalankannya
 * dengan argumen `-S`/`artisan` hanya mencetak bantuan FPM dan tidak melakukan apa-apa.
 * Kode yang dipanggil dari request web (tombol "Jalankan demo", dll.) harus memakai ini.
 */
final class CliPhp
{
    public static function path(): string
    {
        if (in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true) && PHP_BINARY !== '') {
            return PHP_BINARY;
        }

        $sameVersion = PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        if (is_executable($sameVersion)) {
            return $sameVersion;
        }

        return is_executable(PHP_BINDIR.'/php') ? PHP_BINDIR.'/php' : 'php';
    }
}
