<?php

namespace App\Http\Controllers;

use App\Services\AgentConfiguration;
use App\Services\AgentModelCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            'pack' => $configs->packs()['profiles'][$slug],
            'catalog' => $ready ? $catalog->fetch($slug) : ['status' => 'offline', 'providers' => []],
            'versions' => $ready ? DB::table('agent_config_versions')->where('role', $slug)->orderByDesc('config_version')->get() : collect(),
        ]);
    }

    public function update(Request $request, string $slug, AgentConfiguration $configs): JsonResponse|RedirectResponse
    {
        $configs->role($slug);
        abort_unless($configs->schemaReady(), 503, 'Migrasi konfigurasi belum diterapkan.');
        $data = $request->validate([
            'config_version' => ['required', 'integer', 'min:0'],
            'provider' => ['nullable', 'string', 'max:128'],
            'model' => ['nullable', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:24000'],
            'soul' => ['required', 'string', 'max:16000'],
            'skills' => ['sometimes', 'array', 'max:10'],
            'skills.*' => ['string', 'distinct', Rule::in($configs->packs()['profiles'][$slug]['skills'])],
            'base_url' => ['prohibited'], 'api_key' => ['prohibited'], 'profile' => ['prohibited'], 'policy' => ['prohibited'],
        ]);
        $data['config_version'] = (int) $data['config_version'];
        $data['provider'] = $data['provider'] ?? '';
        $data['model'] = $data['model'] ?? '';
        $data['skills'] = $data['skills'] ?? [];
        $result = $configs->save($slug, $data, $request->user());

        return $this->saved($request, $slug, $result);
    }

    public function history(string $slug, AgentConfiguration $configs): JsonResponse
    {
        $configs->role($slug);
        abort_unless($configs->schemaReady(), 503, 'Migrasi konfigurasi belum diterapkan.');
        $versions = DB::table('agent_config_versions')->where('role', $slug)->orderByDesc('config_version')->get()->map(function ($row): array {
            return [
                'config_version' => $row->config_version, 'actor_id' => $row->actor_id, 'actor_name' => $row->actor_name, 'created_at' => $row->created_at,
                'provider' => $row->provider, 'model' => $row->model, 'instructions' => $row->instructions, 'soul' => $row->soul,
                'skills' => json_decode($row->skills, true), 'status' => $row->status,
            ];
        });

        return response()->json(['versions' => $versions]);
    }

    public function restore(Request $request, string $slug, int $version, AgentConfiguration $configs): JsonResponse|RedirectResponse
    {
        $configs->role($slug);
        abort_unless($configs->schemaReady(), 503, 'Migrasi konfigurasi belum diterapkan.');
        $data = $request->validate(['config_version' => ['required', 'integer', 'min:0']]);
        $old = $configs->version($slug, $version);
        abort_unless($old, 404);
        $result = $configs->save($slug, [
            'config_version' => (int) $data['config_version'], 'provider' => $old['provider'], 'model' => $old['model'],
            'instructions' => $old['instructions'], 'soul' => $old['soul'], 'skills' => $old['skills'],
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

        return redirect()->route('agents.config.edit', $slug)->with('status', $result['status'] === 'verified'
            ? 'Snapshot aplikasi tersimpan; katalog terverifikasi. Model served belum dibuktikan hingga respons konsultasi.'
            : 'Draft aplikasi tersimpan; provider/model pending dan konsultasi konfigurasi ini terkunci.');
    }
}
