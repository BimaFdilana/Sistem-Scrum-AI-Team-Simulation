<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AgentConfiguration
{
    public function role(string $slug): array
    {
        $role = collect(config('agents.profiles'))->firstWhere('slug', $slug);
        abort_unless($role, 404);

        return $role;
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('agent_role_settings') && Schema::hasTable('agent_config_versions') && Schema::hasTable('agent_dispatches');
    }

    public function packs(): array
    {
        return json_decode(file_get_contents(resource_path('agents/role-packs.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function current(string $role): ?array
    {
        if (! $this->schemaReady()) {
            return null;
        }
        $row = DB::table('agent_role_settings')->where('role', $role)->first();

        return $row ? $this->version($role, $row->config_version) : null;
    }

    public function version(string $role, int $version): ?array
    {
        $row = DB::table('agent_config_versions')->where('role', $role)->where('config_version', $version)->first();
        if (! $row) {
            return null;
        }
        $data = (array) $row;
        foreach (['skills', 'skill_snapshot'] as $key) {
            $data[$key] = json_decode($data[$key], true, flags: JSON_THROW_ON_ERROR);
        }

        return $data;
    }

    public function defaults(string $role): array
    {
        $pack = $this->packs()['profiles'][$role];
        $settings = app(AgentModelCatalog::class)->settings($role);

        return ['config_version' => 0, 'provider' => (string) ($settings['provider'] ?? ''), 'model' => (string) ($settings['model'] ?? ''), 'instructions' => $pack['instructions'], 'soul' => $pack['soul'], 'skills' => $pack['skills'], 'status' => 'pending'];
    }

    public function save(string $role, array $data, User $actor): array
    {
        $agent = $this->role($role);
        abort_unless($this->schemaReady(), 503, 'Migrasi konfigurasi belum diterapkan.');
        $current = $this->current($role) ?? $this->defaults($role);
        abort_unless($current['config_version'] === $data['config_version'], 409, 'Konfigurasi telah berubah. Muat ulang editor.');
        $catalogService = app(AgentModelCatalog::class);
        $catalog = $catalogService->fetch($role);
        $verified = $catalogService->contains($catalog, $data['provider'], $data['model']);
        $changed = $data['provider'] !== $current['provider'] || $data['model'] !== $current['model'];
        if ($changed && ! $verified) {
            throw ValidationException::withMessages(['model' => 'Perubahan provider/model membutuhkan katalog runtime tersedia dan terautentikasi.']);
        }
        $status = $verified ? 'verified' : 'pending';
        $snapshot = [];
        $pack = $this->packs()['profiles'][$role];
        foreach ($data['skills'] as $id) {
            if (! in_array($id, $pack['skills'], true)) {
                throw ValidationException::withMessages(['skills' => 'Skill tidak tersedia untuk peran ini.']);
            }
            $snapshot[$id] = ['body' => $pack['skill_body'], 'hash' => hash('sha256', $pack['skill_body'])];
        }
        try {
            return DB::transaction(function () use ($role, $data, $actor, $agent, $status, $snapshot): array {
                $locked = DB::table('agent_role_settings')->where('role', $role)->lockForUpdate()->first();
                abort_unless(($locked?->config_version ?? 0) === $data['config_version'], 409, 'Konfigurasi telah berubah.');
                if ($status === 'verified' && DB::table('agent_role_settings')->where('role', '!=', $role)->where('active_model', $data['model'])->exists()) {
                    throw ValidationException::withMessages(['model' => 'Model aktif harus berbeda untuk setiap peran.']);
                }
                $version = $data['config_version'] + 1;
                $id = DB::table('agent_config_versions')->insertGetId([
                    'role' => $role, 'profile' => $agent['profile'], 'config_version' => $version,
                    'actor_id' => $actor->id, 'actor_name' => $actor->name, 'provider' => $data['provider'], 'model' => $data['model'],
                    'instructions' => $data['instructions'], 'soul' => $data['soul'], 'policy_snapshot' => $this->packs()['common_policy'], 'skills' => json_encode(array_values($data['skills'])),
                    'skill_snapshot' => json_encode($snapshot), 'status' => $status, 'created_at' => now(),
                ]);
                $pointer = ['version_id' => $id, 'config_version' => $version, 'active_model' => $status === 'verified' ? $data['model'] : null];
                if ($locked) {
                    $updated = DB::table('agent_role_settings')->where('role', $role)->where('config_version', $data['config_version'])->update($pointer);
                    abort_unless($updated === 1, 409);
                } else {
                    DB::table('agent_role_settings')->insert(['role' => $role, ...$pointer]);
                }

                return ['config_version' => $version, 'status' => $status];
            });
        } catch (QueryException) {
            // Includes racing first saves and unique model activation. Never echo SQL or credentials.
            abort(409, 'Konflik konfigurasi atau model aktif. Muat ulang editor.');
        }
    }
}
