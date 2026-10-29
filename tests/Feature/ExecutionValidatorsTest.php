<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use App\Jobs\ReviewExecutionTaskJob;
use Tests\TestCase;

class ExecutionValidatorsTest extends TestCase
{
    private function callPrivate(object $job, string $method, string $raw): mixed
    {
        $ref = new \ReflectionMethod($job, $method);
        $ref->setAccessible(true);

        return $ref->invoke($job, $raw);
    }

    private function makePatchJson(): string
    {
        return json_encode(['files' => [
            ['path' => 'resources/css/components/_scroll-to-top.css', 'action' => 'create', 'content' => '.to-top{display:none}'],
        ], 'summary' => 'Styling tombol scroll-to-top.']);
    }

    public function test_fenced_patch_json_is_accepted(): void
    {
        // Regresi eksekusi US-01 #2: patch task #2 dibungkus fence + prosa
        // dulu langsung gagal JsonException "Syntax error".
        $job = new ExecuteExecutionTaskJob(0, 0);
        $patch = $this->callPrivate($job, 'validatedPatch', "Berikut patch-nya:\n```json\n".$this->makePatchJson()."\n```");

        $this->assertSame('resources/css/components/_scroll-to-top.css', $patch['files'][0]['path']);
    }

    public function test_prose_wrapped_patch_json_is_accepted(): void
    {
        $job = new ExecuteExecutionTaskJob(0, 0);
        $patch = $this->callPrivate($job, 'validatedPatch', 'Tentu, ini patch-nya: '.$this->makePatchJson().' Semoga membantu.');

        $this->assertSame('Styling tombol scroll-to-top.', $patch['summary']);
    }

    public function test_refusal_text_fails_patch_validation(): void
    {
        $this->expectException(\Throwable::class);
        $this->callPrivate(new ExecuteExecutionTaskJob(0, 0), 'validatedPatch', 'Maaf, saya tidak dapat membantu.');
    }

    public function test_fenced_review_json_is_accepted(): void
    {
        // Regresi eksekusi US-01 #2: review QA task #1 gagal parse
        // (13:27:19 UTC) dan menimpa kode akhir menjadi REVIEW_FAILED.
        $job = new ReviewExecutionTaskJob(0, 0);
        $review = $this->callPrivate($job, 'validatedReview', "Hasil review:\n```json\n".json_encode(['approved' => true, 'feedback' => 'Bagus.'])."\n```");

        $this->assertTrue($review['approved']);
    }

    public function test_prose_wrapped_review_json_is_accepted(): void
    {
        $job = new ReviewExecutionTaskJob(0, 0);
        $review = $this->callPrivate($job, 'validatedReview', 'Review saya: '.json_encode(['approved' => false, 'feedback' => 'Perbaiki warna.']).' Terima kasih.');

        $this->assertFalse($review['approved']);
        $this->assertSame('Perbaiki warna.', $review['feedback']);
    }

    public function test_refusal_text_fails_review_validation(): void
    {
        $this->expectException(\Throwable::class);
        $this->callPrivate(new ReviewExecutionTaskJob(0, 0), 'validatedReview', 'Maaf, saya tidak dapat membantu.');
    }
}
