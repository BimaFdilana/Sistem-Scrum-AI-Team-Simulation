<?php

namespace Tests\Feature;

use App\Jobs\ImportGithubProjectJob;
use App\Models\Project;
use App\Models\User;
use App\Services\PublicGithubRepositoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GithubProjectIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_github_import_claims_verification_and_dispatches_unique_job(): void
    {
        Queue::fake();
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post('/projects', [
            'name' => 'Imported Laravel',
            'description' => 'Public baseline.',
            'project_type' => 'EXISTING_PROJECT',
            'github_repository_url' => 'https://github.com/acme/portal',
            'github_branch' => 'main',
        ]);

        $project = Project::query()->sole();
        $response->assertRedirect('/projects/'.$project->id)->assertSessionHas('status');
        $this->assertSame('SOURCE_VERIFYING', $project->status);
        $this->assertSame('https://github.com/acme/portal.git', $project->source_repository_url);
        $this->assertSame('main', $project->source_branch);
        Queue::assertPushed(ImportGithubProjectJob::class, fn ($job) => $job->projectId === $project->id);
    }

    public function test_github_url_rejects_private_credentials_queries_and_non_github_hosts(): void
    {
        foreach ([
            'https://user:password@github.com/acme/portal',
            'https://github.com/acme/portal?token=secret',
            'https://gitlab.com/acme/portal',
            'git@github.com:acme/portal.git',
        ] as $url) {
            $this->expectException(\RuntimeException::class);
            PublicGithubRepositoryService::normalizeUrl($url);
        }
    }

    public function test_new_project_does_not_accept_github_repository_url(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->from('/projects/create')->post('/projects', [
            'name' => 'Invalid New Project',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
            'github_repository_url' => 'https://github.com/acme/portal',
        ])->assertRedirect('/projects/create')->assertSessionHasErrors('github_repository_url');

        $this->assertDatabaseCount('projects', 0);
    }
}
