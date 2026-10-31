<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar bila satu role belum punya model yang dipilih PO secara
 * eksplisit di halaman Konfigurasi Agent (tidak ada snapshot,
 * belum terverifikasi, atau modelnya tidak tersedia di gateway).
 * Pesan exception ini adalah peringatan yang harus sampai ke PO —
 * pemanggil tidak boleh menggantinya dengan model lain secara diam-diam.
 */
class ModelNotChosenException extends RuntimeException {}
