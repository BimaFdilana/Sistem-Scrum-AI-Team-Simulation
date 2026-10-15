<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\GithubApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pengaturan integrasi GitHub (Personal Access Token PO).
 *
 * Pilihan penyimpanan: database terenkripsi (via UI ini), dengan fallback
 * ke env GITHUB_PAT. Database dipilih karena praktis — Bima bisa atur/putus
 * dari browser tanpa SSH dan tanpa restart container. Nilai dienkripsi
 * dengan APP_KEY (cast 'encrypted' di model Setting).
 */
class GithubSettingsController extends Controller
{
    public function edit(GithubApiClient $client): View
    {
        $me = $client->hasToken() ? $client->me() : null;

        return view('workspace.github-settings', [
            'connected' => $me !== null,
            'githubUser' => $me,
            'hasToken' => $client->hasToken(),
            'fromEnv' => filled(config('services.github.token')) && ! filled(Setting::get('github_pat')),
            'activePage' => 'github-settings',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pat' => ['required', 'string', 'min:20', 'max:255', 'regex:/^[A-Za-z0-9_]+$/'],
        ], [
            'pat.required' => 'Tempel Personal Access Token dari GitHub.',
            'pat.min' => 'Token terlihat terlalu pendek — pastikan kamu meng-copy seluruh token.',
            'pat.regex' => 'Format token tidak valid.',
        ]);

        // Verifikasi dulu sebelum disimpan: token harus valid.
        $valid = $this->verifyToken($data['pat']);

        if (! $valid) {
            return back()->with('error', 'Token tidak valid atau tidak bisa terhubung ke GitHub. Periksa kembali token yang kamu tempel.');
        }

        Setting::put('github_pat', $data['pat']);

        return redirect()->route('settings.github.edit')
            ->with('success', 'Token GitHub tersimpan dan terverifikasi. Website sekarang terhubung ke GitHub kamu.');
    }

    public function disconnect(GithubApiClient $client): RedirectResponse
    {
        $client->forgetToken();

        return redirect()->route('settings.github.edit')
            ->with('success', 'Koneksi GitHub diputus. Token dihapus dari database.');
    }

    /**
     * AJAX: test koneksi dengan token yang sedang ditempel (belum disimpan).
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pat' => ['required', 'string', 'min:20', 'max:255'],
        ]);

        $me = $this->verifyToken($data['pat']);

        if ($me === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Tidak terhubung. Periksa token dan koneksi internet server.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Terhubung sebagai @'.$me['login'],
            'login' => $me['login'],
        ]);
    }

    /**
     * Verifikasi token tanpa menyimpannya: panggil GET /user langsung.
     *
     * @return array{login: string}|null
     */
    protected function verifyToken(string $token): ?array
    {
        try {
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->get('https://api.github.com/user');

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return isset($data['login']) ? ['login' => (string) $data['login']] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
