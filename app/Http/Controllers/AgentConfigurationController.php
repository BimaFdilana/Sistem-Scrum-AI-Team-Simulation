<?php

namespace App\Http\Controllers;

use App\Services\AgentConfiguration;
use App\Services\AgentModelCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgentConfigurationController extends Controller
{
    public function edit(string $slug, AgentConfiguration $configs, AgentModelCatalog $catalog): View
    {
        $agent = $configs->role($slug);
        $ready = $configs->schemaReady();

        return view('workspace.agent-config', [
            'agent' => $agent, 'schemaReady' => $ready, 'activePage' => 'agents',
            'settings' => $configs->current($slug) ?? $configs->defaults($slug),
            'catalog' => $ready ? $catalog->fetch($slug) : ['status' => 'offline', 'providers' => []],
            'versions' => $ready ? DB::table('agent_config_versions')->where('role', $slug)->select('config_version', 'actor_name', 'created_at', 'status', 'provider', 'model')->orderByDesc('config_version')->get() : collect(),
        ]);
    }

    public function update(Request $request, string $slug, AgentConfiguration $configs): JsonResponse|RedirectResponse
    {
        $configs->role($slug);
        abort_unless($configs->schemaReady(), 503, 'Migrasi konfigurasi belum diterapkan.');
        $data = $request->validate([
            'config_version' => ['required', 'integer', 'min:0'],
            'selection' => ['required', 'string', 'max:1024'],
            'provider' => ['prohibited'], 'model' => ['prohibited'],
            'instructions' => ['prohibited'], 'soul' => ['prohibited'], 'skills' => ['prohibited'],
            'base_url' => ['prohibited'], 'api_key' => ['prohibited'], 'profile' => ['prohibited'], 'policy' => ['prohibited'],
        ]);
        $pair = json_decode($data['selection'], true);
        if (! is_array($pair) || array_keys($pair) !== ['provider', 'model'] || ! is_string($pair['provider']) || ! is_string($pair['model'])
            || json_encode($pair, JSON_THROW_ON_ERROR) !== $data['selection']) {
            throw ValidationException::withMessages(['selection' => 'Pilih pasangan provider/model dari katalog.']);
        }
        $result = $configs->save($slug, ['config_version' => (int) $data['config_version'], 'provider' => $pair['provider'], 'model' => $pair['model']], $request->user());

        return $this->saved($request, $slug, $result);
    }

    public function history(string $slug, AgentConfiguration $configs): JsonResponse
    {
        $configs->role($slug);
        abort_unless($configs->schemaReady(), 503, 'Migrasi konfigurasi belum diterapkan.');
        $versions = DB::table('agent_config_versions')->where('role', $slug)
            ->select('config_version', 'actor_id', 'actor_name', 'created_at', 'provider', 'model', 'status')
            ->orderByDesc('config_version')->get()->map(function ($row): array {
                return [
                    'config_version' => $row->config_version, 'actor_id' => $row->actor_id, 'actor_name' => $row->actor_name, 'created_at' => $row->created_at,
                    'provider' => $row->provider, 'model' => $row->model, 'status' => $row->status,
                ];
            });

        return response()->json(['versions' => $versions]);
    }

    public function restore(Request $request, string $slug, int $version, AgentConfiguration $configs): JsonResponse|RedirectResponse
    {
        $configs->role($slug);
        abort_unless($configs->schemaReady(), 503, 'Migrasi konfigurasi belum diterapkan.');
        $data = $request->validate([
            'config_version' => ['required', 'integer', 'min:0'],
            'provider' => ['prohibited'], 'model' => ['prohibited'], 'selection' => ['prohibited'],
            'instructions' => ['prohibited'], 'soul' => ['prohibited'], 'skills' => ['prohibited'],
            'base_url' => ['prohibited'], 'api_key' => ['prohibited'], 'profile' => ['prohibited'], 'policy' => ['prohibited'],
        ]);
        $old = $configs->version($slug, $version);
        abort_unless($old, 404);
        $result = $configs->save($slug, [
            'config_version' => (int) $data['config_version'], 'provider' => $old['provider'], 'model' => $old['model'],
        ], $request->user());

        return $this->saved($request, $slug, $result);
    }

    public function connectivity(string $slug, AgentConfiguration $configs, AgentModelCatalog $catalog): JsonResponse
    {
        $configs->role($slug);
        abort_unless($configs->schemaReady(), 503, 'Migrasi konfigurasi belum diterapkan.');

        return response()->json($catalog->fetch($slug));
    }

    private function saved(Request $request, string $slug, array $result): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return redirect()->route('agents.config.edit', $slug)->with('status', 'Model tersimpan; katalog terverifikasi. Model served belum dibuktikan hingga respons konsultasi.');
    }
}
