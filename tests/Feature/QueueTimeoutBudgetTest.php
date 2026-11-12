<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Anggaran waktu antrean: proses agen < timeout job < retry_after antrean.
 * Bila urutan ini rusak, job panjang dianggap macet lalu diambil ganda (Sprint 2).
 */
class QueueTimeoutBudgetTest extends TestCase
{
    public function test_retry_after_is_longer_than_worker_and_agent_timeouts(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $workerTimeout = 1800; // --timeout pada scrumspace-queue.service

        $this->assertGreaterThan($workerTimeout, $retryAfter);
        $this->assertLessThan($workerTimeout, (int) config('ai.opencode.timeout'));
        $this->assertLessThan($workerTimeout, (int) config('ai.hermes.timeout', 1500));
    }
}
