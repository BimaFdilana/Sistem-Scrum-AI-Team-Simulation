<?php

namespace App\Exceptions;

/**
 * Model yang dipilih PO ternyata bermasalah saat dipakai (ditolak
 * gateway, tidak ditemukan, dinonaktifkan, atau berulang kali
 * mengembalikan respons malformed). errorCode MODEL_PROBLEMATIC
 * SENGAJA tidak termasuk kode transient di AiGateway: alur harus
 * berhenti dengan prompt penggantian, bukan antre ulang atau
 * berpindah diam-diam ke model lain. Pesan exception ini memuat
 * nama model + daftar model available + perintah ganti di menu.
 */
class ModelProblemException extends AiUnavailableException
{
    public function __construct(string $model, string $message)
    {
        parent::__construct('MODEL_PROBLEMATIC', $message, null, $model);
    }
}
