<?php

namespace Tests\Feature;

use App\Services\ActiveModelService;
use App\Services\AntigravityClient;
use App\Services\StrictModelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class RoleModelResolverTest extends TestCase
{
    use RefreshDatabase;

    private function availableModels(array $ids): void
    {
        $client = Mockery::mock(AntigravityClient::class);
        $client->shouldReceive('modelIds')->andReturn($ids);
        $this->app->instance(AntigravityClient::class, $client);
    }

    public function test_role_without_override_uses_the_global_model(): void
    {
        $this->availableModels(['gemini-3.8-flash-medium', 'claude-opus-4-6-thinking']);
        $this->useAiModel('gemini-3.8-flash-medium', ['qa-engineer' => 'claude-opus-4-6-thinking']);

        $resolver = app(StrictModelResolver::class);

        $this->assertSame('gemini-3.8-flash-medium', $resolver->resolve('backend-developer'));
        $this->assertSame('claude-opus-4-6-thinking', $resolver->resolve('qa-engineer'));
    }

    public function test_nothing_chosen_means_no_model_and_no_fallback(): void
    {
        $this->availableModels(['gemini-3.8-flash-medium']);

        $check = app(StrictModelResolver::class)->check('scrum-master');

        $this->assertNull($check['model']);
        $this->assertSame(StrictModelResolver::STATE_NOT_CHOSEN, $check['state']);
    }

    public function test_model_missing_from_account_is_not_available(): void
    {
        $this->availableModels(['gemini-3.8-flash-medium']);
        $this->useAiModel('claude-opus-4-6-thinking');

        $check = app(StrictModelResolver::class)->check('qa-engineer');

        $this->assertNull($check['model']);
        $this->assertSame(StrictModelResolver::STATE_NOT_AVAILABLE, $check['state']);
    }

    public function test_saving_rejects_models_the_account_does_not_have(): void
    {
        $this->availableModels(['gemini-3.8-flash-medium']);

        $this->expectException(ValidationException::class);
        app(ActiveModelService::class)->setForRole('qa-engineer', 'model-palsu');
    }

    public function test_clearing_a_role_override_returns_to_the_global_model(): void
    {
        $this->availableModels(['gemini-3.8-flash-medium', 'gemini-3.8-flash-high']);
        $service = app(ActiveModelService::class);
        $service->set('gemini-3.8-flash-medium');
        $service->setForRole('scrum-master', 'gemini-3.8-flash-high');

        $this->assertSame('gemini-3.8-flash-high', $service->forRole('scrum-master'));

        $service->setForRole('scrum-master', null);

        $this->assertSame('gemini-3.8-flash-medium', $service->forRole('scrum-master'));
    }
}
