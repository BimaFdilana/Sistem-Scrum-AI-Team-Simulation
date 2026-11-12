<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tes TIDAK BOLEH menghubungi layanan sungguhan. Suite dijalankan di VPS
        // produksi; permintaan tanpa fake ke gateway AI (127.0.0.1:8317) memakai
        // kunci tes yang salah, dihitung gateway sebagai percobaan gagal, lalu
        // IP server diblokir sehingga halaman AI Providers ikut rusak (13 Nov 2026).
        // Permintaan yang tidak di-fake kini langsung gagal di dalam tes.
        Http::preventStrayRequests();
    }
}
