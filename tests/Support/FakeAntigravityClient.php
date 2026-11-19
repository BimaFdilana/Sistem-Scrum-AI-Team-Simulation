<?php

namespace Tests\Support;

use App\Services\AiUsageRecorder;
use App\Services\AntigravityClient;
use App\Support\Ai\AiQuota;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

/**
 * Pengganti AntigravityClient untuk tes: TIDAK pernah menjalankan proses agy.
 *
 * Panggilan model diteruskan ke endpoint HTTP palsu (http://127.0.0.1:8317)
 * berformat chat-completions, sehingga tes cukup memakai Http::fake untuk
 * menentukan jawaban agen. Panggilan yang tidak di-fake gagal karena
 * Http::preventStrayRequests() di TestCase.
 */
class FakeAntigravityClient extends AntigravityClient
{
    public const BASE_URL = 'http://127.0.0.1:8317';

    public function isInstalled(): bool
    {
        return true;
    }

    public function version(): ?string
    {
        return '1.0.0-test';
    }

    public function models(bool $fresh = false): array
    {
        try {
            $data = Http::get(self::BASE_URL.'/v1/models')->json('data');
        } catch (Throwable) {
            return [];
        }

        return collect(is_array($data) ? $data : [])
            ->map(fn ($model) => is_array($model) && isset($model['id']) ? ['id' => (string) $model['id'], 'name' => (string) $model['id']] : null)
            ->filter()->values()->all();
    }

    public function forgetModelsCache(): void {}

    public function chatResilient(string $model, string $systemPrompt, string $userMessage, int $timeout = 0, ?array $image = null, array $usageContext = []): array
    {
        if (! $this->isEnabled()) {
            return ['text' => null, 'error' => 'DISABLED', 'model' => null, 'retry_after' => null];
        }

        $attempts = max(1, (int) config('ai.antigravity.chat_attempts', 2));
        $error = 'UNKNOWN';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            [$status, $error, $text, $usage] = $this->attempt($model, $systemPrompt, $userMessage, $image);
            if ($status === 'ok') {
                AiUsageRecorder::record($model, $usage, $usageContext);
                AiQuota::recovered($model, false);

                return ['text' => $text, 'error' => null, 'model' => $model, 'retry_after' => null, 'usage' => $usage];
            }
            if ($error === 'MODEL_PROBLEMATIC') {
                return ['text' => null, 'error' => $error, 'model' => $model, 'retry_after' => null, 'available_models' => $this->modelIds()];
            }
            if ($status !== 'transient') {
                break;
            }
        }

        return ['text' => null, 'error' => $error, 'model' => null, 'retry_after' => null];
    }

    public function runTask(string $agent, string $worktree, string $prompt, string $model, int $timeout = 0, array $usageContext = []): array
    {
        throw new LogicException('runTask tidak di-fake di tes ini. Mock AntigravityClient secara eksplisit.');
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string, 3: array}
     */
    private function attempt(string $model, string $systemPrompt, string $userMessage, ?array $image): array
    {
        try {
            $response = Http::post(self::BASE_URL.'/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'image' => $image !== null,
            ]);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'stray')) {
                throw $exception;
            }

            return ['transient', 'TIMEOUT', null, []];
        }

        $detail = (string) ($response->json('error.message') ?? $response->body());
        $result = match (true) {
            $response->status() === 401 => ['permanent', 'NOT_LOGGED_IN'],
            $response->status() === 404 => ['permanent', 'MODEL_PROBLEMATIC'],
            $response->status() === 429 && AiQuota::isQuotaText($detail) => ['permanent', 'QUOTA_EXHAUSTED'],
            $response->status() === 429 => ['transient', 'RATE_LIMITED'],
            $response->status() >= 500 => ['transient', 'UPSTREAM_OVERLOADED'],
            ! $response->successful() => ['permanent', 'AGY_FAILED'],
            default => null,
        };
        if ($result !== null) {
            if ($result[1] === 'QUOTA_EXHAUSTED') {
                AiQuota::exhausted($model, $detail);
            }

            return [$result[0], $result[1], null, []];
        }

        $text = $response->json('choices.0.message.content');
        if (! is_string($text) || trim($text) === '') {
            return ['transient', 'EMPTY_RESPONSE', null, []];
        }
        $usage = $response->json('usage');

        return ['ok', null, trim($text), is_array($usage) ? $usage : []];
    }
}
