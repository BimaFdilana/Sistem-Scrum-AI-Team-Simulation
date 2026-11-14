<?php

namespace Tests\Feature;

use App\Models\DemoServer;
use App\Models\Project;
use App\Models\User;
use App\Support\Demo\DemoAccounts;
use App\Support\Demo\DemoServerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PDO;
use Tests\TestCase;

class DemoAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function demoDatabase(string $usersDdl, array $rows = []): string
    {
        $path = storage_path('framework/testing/demo-'.uniqid().'.sqlite');
        $pdo = new PDO('sqlite:'.$path);
        if ($usersDdl !== '') {
            $pdo->exec($usersDdl);
        }
        foreach ($rows as $row) {
            $columns = implode(',', array_keys($row));
            $marks = implode(',', array_fill(0, count($row), '?'));
            $pdo->prepare("INSERT INTO users ($columns) VALUES ($marks)")->execute(array_values($row));
        }

        return $path;
    }

    private function seeded(string $path): array
    {
        return (new PDO('sqlite:'.$path))->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    public function test_every_listed_account_gets_the_new_random_password_and_is_returned(): void
    {
        $old = Hash::make('password');
        $path = $this->demoDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, role TEXT, email_verified_at TEXT)', [
            ['name' => 'Admin', 'email' => 'admin@demo.test', 'password' => $old, 'role' => 'admin', 'email_verified_at' => null],
            ['name' => 'Pengusul', 'email' => 'pengusul@demo.test', 'password' => $old, 'role' => 'pengusul', 'email_verified_at' => null],
        ]);

        $result = app(DemoAccounts::class)->provision($path);

        $this->assertSame(12, strlen($result['password']));
        $this->assertSame(['admin@demo.test', 'pengusul@demo.test'], array_column($result['accounts'], 'email'));
        $this->assertSame(['admin', 'pengusul'], array_column($result['accounts'], 'role'));
        foreach ($this->seeded($path) as $user) {
            $this->assertTrue(Hash::check($result['password'], $user['password']), 'kata sandi baru berlaku');
            $this->assertFalse(Hash::check('password', $user['password']), 'tebakan lama tidak lagi berlaku');
            $this->assertNotNull($user['email_verified_at'], 'akun siap dipakai masuk');
        }
    }

    public function test_each_start_gets_a_different_password(): void
    {
        $path = $this->demoDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password TEXT)', [['email' => 'a@demo.test', 'password' => 'x']]);

        $first = app(DemoAccounts::class)->provision($path)['password'];
        $second = app(DemoAccounts::class)->provision($path)['password'];

        $this->assertNotSame($first, $second);
    }

    public function test_lists_at_most_twelve_accounts_and_leaves_the_rest_untouched(): void
    {
        $rows = [];
        for ($i = 1; $i <= 15; $i++) {
            $rows[] = ['email' => "u{$i}@demo.test", 'password' => 'hash-asli'];
        }
        $path = $this->demoDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password TEXT)', $rows);

        $result = app(DemoAccounts::class)->provision($path);

        $this->assertCount(12, $result['accounts']);
        $this->assertSame(['hash-asli', 'hash-asli', 'hash-asli'], array_column(array_slice($this->seeded($path), 12), 'password'));
    }

    public function test_databases_without_a_usable_users_table_yield_no_accounts_and_no_error(): void
    {
        $empty = ['password' => null, 'accounts' => []];

        $this->assertSame($empty, app(DemoAccounts::class)->provision($this->demoDatabase('')));
        $this->assertSame($empty, app(DemoAccounts::class)->provision($this->demoDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY, nama TEXT)')));
        $this->assertSame($empty, app(DemoAccounts::class)->provision($this->demoDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password TEXT)')));
        $this->assertSame($empty, app(DemoAccounts::class)->provision('/path/yang/tidak/ada.sqlite'));
    }

    public function test_project_page_shows_accounts_and_password_only_while_the_demo_runs(): void
    {
        $project = Project::query()->create(['name' => 'DA', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $demo = new DemoServer(['project_id' => $project->id, 'port' => 9101, 'status' => 'RUNNING', 'credentials' => [
            'password' => 'RAHASIA-DEMO-77',
            'accounts' => [['name' => 'Admin Demo', 'email' => 'admin@demo.test', 'role' => 'admin']],
        ]]);
        $this->mock(DemoServerManager::class, function ($mock) use ($demo): void {
            $mock->shouldReceive('runningFor')->andReturn($demo);
            $mock->shouldReceive('url')->andReturn('http://demo.test:9101');
        });

        $this->actingAs(User::factory()->create())->get(route('projects.release.show', $project))
            ->assertOk()
            ->assertSee('Akun untuk masuk ke demo')
            ->assertSee('admin@demo.test')
            ->assertSee('Admin Demo')
            ->assertSee('RAHASIA-DEMO-77');
    }

    public function test_project_page_without_a_running_demo_shows_no_credentials(): void
    {
        $project = Project::query()->create(['name' => 'DB', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $this->mock(DemoServerManager::class, fn ($mock) => $mock->shouldReceive('runningFor')->andReturn(null));

        $this->actingAs(User::factory()->create())->get(route('projects.release.show', $project))
            ->assertOk()->assertDontSee('Akun untuk masuk ke demo')->assertDontSee('id="demo-password"', false);
    }

    public function test_credentials_are_stored_encrypted_in_the_database(): void
    {
        $project = Project::query()->create(['name' => 'DC', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $demo = DemoServer::query()->create(['project_id' => $project->id, 'port' => 9102, 'pid' => 1, 'status' => 'RUNNING', 'credentials' => ['password' => 'KATA-SANDI-POLOS', 'accounts' => []]]);

        $raw = DB::table('demo_servers')->where('id', $demo->id)->value('credentials');

        $this->assertStringNotContainsString('KATA-SANDI-POLOS', (string) $raw);
        $this->assertSame('KATA-SANDI-POLOS', $demo->fresh()->credentials['password']);
    }

    public function test_demo_started_before_this_feature_tells_the_po_to_restart_it(): void
    {
        $project = Project::query()->create(['name' => 'DO', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $this->mock(DemoServerManager::class, function ($mock) use ($project): void {
            $mock->shouldReceive('runningFor')->andReturn(new DemoServer(['project_id' => $project->id, 'port' => 9103, 'status' => 'RUNNING']));
            $mock->shouldReceive('url')->andReturn('http://demo.test:9103');
        });

        $this->actingAs(User::factory()->create())->get(route('projects.release.show', $project))
            ->assertOk()->assertSee('hentikan lalu jalankan ulang demo', false)->assertDontSee('id="demo-password"', false);
    }

    public function test_demo_without_any_account_says_there_is_nothing_to_show(): void
    {
        $project = Project::query()->create(['name' => 'DN', 'project_type' => 'NEW_PROJECT', 'stack' => 'Laravel', 'status' => 'BASELINE_READY']);
        $this->mock(DemoServerManager::class, function ($mock) use ($project): void {
            $mock->shouldReceive('runningFor')->andReturn(new DemoServer(['project_id' => $project->id, 'port' => 9104, 'status' => 'RUNNING', 'credentials' => ['password' => null, 'accounts' => []]]));
            $mock->shouldReceive('url')->andReturn('http://demo.test:9104');
        });

        $this->actingAs(User::factory()->create())->get(route('projects.release.show', $project))
            ->assertOk()->assertSee('Tidak ada akun yang bisa ditampilkan')->assertDontSee('id="demo-password"', false);
    }
}
