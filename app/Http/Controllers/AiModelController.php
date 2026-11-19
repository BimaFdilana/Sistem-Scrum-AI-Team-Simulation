<?php

namespace App\Http\Controllers;

use App\Services\ActiveModelService;
use App\Services\AiUsageRecorder;
use App\Services\AntigravityClient;
use App\Services\AntigravityLogin;
use App\Support\Ai\AiQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Halaman AI Model: login akun Google untuk Antigravity CLI, model global dan
 * per peran, uji koneksi, dan pemakaian token.
 */
class AiModelController extends Controller
{
    public function index(AntigravityClient $client, ActiveModelService $activeModel): View
    {
        $roles = $activeModel->roles();

        return view('workspace.ai-model', [
            'activePage' => 'ai-providers',
            'status' => $client->status(),
            'models' => $client->models(),
            'activeModel' => $activeModel->current(),
            'roles' => $roles,
            'roleModels' => collect($roles)->mapWithKeys(fn ($name, $slug) => [$slug => $activeModel->roleOverride($slug)])->all(),
            'usage' => AiUsageRecorder::summary(30),
            'quota' => AiQuota::current(),
        ]);
    }

    public function updateModels(Request $request, ActiveModelService $activeModel): RedirectResponse
    {
        $validated = $request->validate([
            'active_model' => ['required', 'string', 'max:120'],
            'role_models' => ['array'],
            'role_models.*' => ['nullable', 'string', 'max:120'],
        ], [
            'active_model.required' => 'Pilih model utama untuk semua agen.',
        ]);

        $activeModel->set($validated['active_model']);
        foreach (array_keys($activeModel->roles()) as $role) {
            $activeModel->setForRole($role, $validated['role_models'][$role] ?? null);
        }
        AiQuota::clear();

        return back()->with('status', 'Pilihan model disimpan.');
    }

    public function refreshModels(AntigravityClient $client): RedirectResponse
    {
        $count = count($client->models(true));

        return back()->with($count > 0 ? 'status' : 'error', $count > 0
            ? 'Daftar model diperbarui: '.$count.' model tersedia.'
            : 'Daftar model kosong. Pastikan akun Google sudah terhubung.');
    }

    public function test(Request $request, AntigravityClient $client, ActiveModelService $activeModel): RedirectResponse
    {
        $model = (string) ($request->input('model') ?: $activeModel->current());
        if ($model === '') {
            return back()->with('error', 'Pilih model utama dulu sebelum menguji koneksi.');
        }

        $started = microtime(true);
        $result = $client->chatResilient($model, 'Jawab hanya dengan kata: SIAP', 'Uji koneksi.', 90);
        $seconds = number_format(microtime(true) - $started, 1);

        if ($result['text'] === null) {
            return back()->with('error', 'Uji koneksi '.$model.' gagal ('.$result['error'].').');
        }

        return back()->with('status', 'Koneksi '.$model.' berhasil dalam '.$seconds.' detik. Jawaban: '.mb_substr($result['text'], 0, 80));
    }

    public function startLogin(AntigravityLogin $login): JsonResponse
    {
        return response()->json(['session' => $login->startLogin()]);
    }

    public function startLogout(AntigravityLogin $login): JsonResponse
    {
        return response()->json(['session' => $login->startLogout()]);
    }

    public function sessionState(string $session, AntigravityLogin $login, AntigravityClient $client): JsonResponse
    {
        try {
            $state = $login->state($session);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        if (in_array($state['status'], ['submitted', 'already_logged_in'], true)) {
            $state['logged_in'] = $client->status(true)['logged_in'];
        }

        return response()->json($state);
    }

    public function submitCode(Request $request, string $session, AntigravityLogin $login): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:1024']]);

        try {
            $login->submitCode($session, (string) $request->input('code'));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['status' => 'submitting']);
    }
}
