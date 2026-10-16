<?php

namespace App\Support\Workspace;

use RuntimeException;

/**
 * Dilempar saat patch DITOLAK pada fase validasi (sebelum ada file yang
 * ditulis): path tidak valid, action tidak dikenal, content bermasalah,
 * guardrail anti-destruktif, dsb.
 *
 * Membedakan error validasi dari error tulis penting agar pemanggil bisa
 * memutuskan: error validasi layak di-retry dengan patch yang lebih kecil,
 * sedangkan error tulis (disk penuh, izin berubah) tidak ada gunanya di-retry.
 */
class PatchValidationException extends RuntimeException
{
    //
}
