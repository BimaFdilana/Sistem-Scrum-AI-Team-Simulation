<?php

namespace Tests\Feature;

use App\Services\TestGate;
use App\Support\Projects\ProjectEnvironment;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectEnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_KEY=base64:rahasia-scrumspace');
        putenv('GITHUB_TOKEN=ghp_rahasia');
        putenv('CLIPROXY_API_KEY=rahasia-gateway');
        putenv('DB_DATABASE=/rahasia/scrumspace.sqlite');
        putenv('CUSTOM_VENDOR_SECRET=xyz');
    }

    protected function tearDown(): void
    {
        foreach (['APP_KEY', 'GITHUB_TOKEN', 'CLIPROXY_API_KEY', 'DB_DATABASE', 'CUSTOM_VENDOR_SECRET'] as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    public function test_child_process_does_not_inherit_scrumspace_secrets(): void
    {
        $process = Process::fromShellCommandline('printf "%s|%s|%s|%s|%s" "$APP_KEY" "$GITHUB_TOKEN" "$CLIPROXY_API_KEY" "$DB_DATABASE" "$CUSTOM_VENDOR_SECRET"', null, ProjectEnvironment::scrubbed());
        $process->run();

        $this->assertSame('||||', $process->getOutput());
    }

    public function test_path_and_unrelated_variables_survive(): void
    {
        $process = Process::fromShellCommandline('printf "%s" "$PATH"', null, ProjectEnvironment::scrubbed());
        $process->run();

        $this->assertNotSame('', $process->getOutput());
    }

    public function test_explicit_values_win_over_scrubbing(): void
    {
        $process = Process::fromShellCommandline('printf "%s" "$DB_DATABASE"', null, ProjectEnvironment::scrubbed(['DB_DATABASE' => '/demo/db.sqlite']));
        $process->run();

        $this->assertSame('/demo/db.sqlite', $process->getOutput());
    }

    public function test_test_gate_runs_project_test_command_without_secrets(): void
    {
        $dir = storage_path('framework/testing/env-'.uniqid());
        mkdir($dir, 0700, true);

        $failure = app(TestGate::class)->run($dir, [], 'test -z "$APP_KEY" && test -z "$GITHUB_TOKEN" && test -z "$CLIPROXY_API_KEY"');

        $this->assertNull($failure, (string) $failure);
    }
}
