<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class AgentModelCatalog
{
    public function settings(string $role): array
    {
        return (array) config($role === 'scrum-master' ? 'services.hermes' : 'services.agent_profiles.'.$role, []);
    }

    public function fetch(string $role): array
    {
        $settings = $this->settings($role);
        $offline = ['status' => 'offline', 'providers' => [], 'capabilities' => ['catalog' => false, 'inference_checked' => false, 'execution_enabled' => false]];
        if (empty($settings['base_url']) || empty($settings['api_key'])) {
            return $offline;
        }
        try {
            $root = preg_replace('#/v1$#', '', rtrim($settings['base_url'], '/'));
            $response = Http::acceptJson()->withToken($settings['api_key'])->connectTimeout(1)->timeout(3)->get($root.'/api/model/options');
            if (! $response->successful() || ! is_array($response->json('providers'))) {
                return $offline;
            }
            $providers = [];
            foreach ($response->json('providers') as $row) {
                if (! is_array($row) || ! $this->safeId($row['slug'] ?? null)) {
                    continue;
                }
                // Hermes inventory's explicit booleans only; catalog presence is NOT authentication.
                $ready = (($row['available'] ?? null) === true || ($row['authenticated'] ?? null) === true)
                    && ($row['available'] ?? null) !== false && ($row['authenticated'] ?? null) !== false
                    && ($row['free_tier_pending'] ?? false) !== true;
                $unavailable = (array) ($row['unavailable_models'] ?? []);
                $models = array_values(array_unique(array_filter((array) ($row['models'] ?? []),
                    fn ($model) => $this->safeId($model) && ! in_array($model, $unavailable, true))));
                $name = is_string($row['name'] ?? null) ? mb_substr(strip_tags($row['name']), 0, 100) : $row['slug'];
                if (preg_match('#://|(?:^|\s)/#', $name)) {
                    $name = $row['slug'];
                }
                $providers[] = ['slug' => $row['slug'], 'name' => $name, 'available' => $ready, 'models' => $ready ? $models : []];
            }

            return ['status' => 'online', 'providers' => $providers, 'capabilities' => ['catalog' => true, 'inference_checked' => false, 'execution_enabled' => false]];
        } catch (Throwable) {
            return $offline;
        }
    }

    public function contains(array $catalog, string $provider, string $model): bool
    {
        foreach ($catalog['providers'] as $row) {
            if ($row['slug'] === $provider && $row['available'] && in_array($model, $row['models'], true)) {
                return true;
            }
        }

        return false;
    }

    public function safeId(mixed $id): bool
    {
        return is_string($id) && strlen($id) <= 255 && ! str_contains($id, '://') && ! str_contains($id, '..') && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]*\z/D', $id) === 1;
    }
}
