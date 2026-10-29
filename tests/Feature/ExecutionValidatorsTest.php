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

    public function test_braceless_fenced_review_is_accepted(): void
    {
        // Regresi eksekusi US-01 #5 (14:42:37 UTC): QA mengembalikan field
        // approved/feedback di dalam fence TAPI TANPA kurung kurawal
        // pembungkus, sehingga extractJsonObject mengembalikan null dan
        // catch handle() langsung menandai run FAILED/REVIEW_FAILED.
        $job = new ReviewExecutionTaskJob(0, 0);
        $review = $this->callPrivate($job, 'validatedReview', "```json\n\"approved\":false, \"feedback\":\"Ada beberapa isu yang harus diperbaiki: tap-target landscape 44x44px.\"\n```");

        $this->assertFalse($review['approved']);
        $this->assertStringContainsString('tap-target landscape', $review['feedback']);
    }

    public function test_braceless_review_with_trailing_prose_is_accepted(): void
    {
        $job = new ReviewExecutionTaskJob(0, 0);
        $review = $this->callPrivate($job, 'validatedReview', '"approved":true, "feedback":"Bagus." Terima kasih atas kerja samanya.');

        $this->assertTrue($review['approved']);
        $this->assertSame('Bagus.', $review['feedback']);
    }

    public function test_braceless_review_without_approved_field_still_fails(): void
    {
        $this->expectException(\Throwable::class);
        $this->callPrivate(new ReviewExecutionTaskJob(0, 0), 'validatedReview', '"feedback":"Tidak ada field approved di sini."');
    }

    public function test_nonapproved_review_with_empty_feedback_fails(): void
    {
        // Regresi eksekusi US-01 #7: feedback akhir task styling tersimpan
        // kosong, sehingga putaran revisi berjalan tanpa arahan perbaikan.
        // Penolakan tanpa feedback kini diperlakukan sebagai review
        // malformed (masuk jalur retry), bukan disimpan apa adanya.
        $this->expectException(\Throwable::class);
        $this->callPrivate(new ReviewExecutionTaskJob(0, 0), 'validatedReview', json_encode(['approved' => false, 'feedback' => '']));
    }

    public function test_nonapproved_review_with_missing_feedback_fails(): void
    {
        $this->expectException(\Throwable::class);
        $this->callPrivate(new ReviewExecutionTaskJob(0, 0), 'validatedReview', json_encode(['approved' => false]));
    }

    public function test_braceless_nonapproved_review_with_empty_feedback_fails(): void
    {
        $this->expectException(\Throwable::class);
        $this->callPrivate(new ReviewExecutionTaskJob(0, 0), 'validatedReview', '"approved":false, "feedback":""');
    }

    public function test_approved_review_with_empty_feedback_passes(): void
    {
        $job = new ReviewExecutionTaskJob(0, 0);
        $review = $this->callPrivate($job, 'validatedReview', json_encode(['approved' => true, 'feedback' => '']));

        $this->assertTrue($review['approved']);
        $this->assertSame('', $review['feedback']);
    }
}
