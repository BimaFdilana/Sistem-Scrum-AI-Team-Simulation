<?php

namespace App\Http\Controllers;

use App\Services\AiStatusService;
use App\Services\CliProxyApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiProviderController extends Controller
{
    public function index(CliProxyApiClient $client, AiStatusService $status): View
    {
        $gatewayUp = $client->isAvailable();
        $managementUp = $client->isManagementAvailable();

        return view('workspace.ai-providers', [
            'providers' => $status->providers(),
            'gatewayUp' => $gatewayUp,
            'managementUp' => $managementUp,
            'activePage' => 'ai-providers',
        ]);
    }

    public function connect(string $provider, CliProxyApiClient $client): View|RedirectResponse
    {
        $providers = config('ai.providers', []);
        abort_unless(isset($providers[$provider]), 404);

        $auth = $client->authUrl($provider);

        if (! $auth) {
            return redirect()->route('ai-providers.index')
                ->with('error', 'Gagal meminta link login dari gateway. Pastikan CLIProxyAPI berjalan dan Management API aktif.');
        }

        session()->put("ai_provider_state.{$provider}", $auth['state']);

        return view('workspace.ai-provider-connect', [
            'provider' => $provider,
            'meta' => $providers[$provider],
            'authUrl' => $auth['url'],
            'state' => $auth['state'],
            'activePage' => 'ai-providers',
        ]);
    }

    public function callback(Request $request, string $provider, CliProxyApiClient $client): RedirectResponse
    {
        $providers = config('ai.providers', []);
        abort_unless(isset($providers[$provider]), 404);

        // Verifikasi aktivasi akun bisa menunggu gateway sampai ~30 detik.
        set_time_limit(60);

        $request->validate([
            'callback_url' => ['required', 'string', 'max:4000'],
        ], [
            'callback_url.required' => 'Tempel URL callback dari address bar browser kamu.',
        ]);

        $callbackUrl = $request->string('callback_url')->toString();
        $code = CliProxyApiClient::extractCode($callbackUrl);

        if (! $code) {
            return back()->with('error', 'URL tersebut tidak mengandung kode otorisasi (parameter ?code=...). Pastikan kamu meng-copy seluruh URL dari address bar setelah menekan Allow/Izinkan.');
        }

        // Utamakan state dari URL yang ditempel: itulah alur login yang
        // benar-benar diselesaikan user. State session hanya cadangan bila
        // URL tidak membawanya (mis. user membuka beberapa tab login).
        $state = CliProxyApiClient::extractState($callbackUrl)
            ?? session()->pull("ai_provider_state.{$provider}");

        if (! $state) {
            return redirect()->route('ai-providers.connect', ['provider' => $provider])
                ->with('error', 'Sesi login kedaluwarsa dan URL tidak mengandung state. Silakan mulai lagi dari tombol Hubungkan.');
        }

        if ($client->submitCallback($state, $code)) {
            // Gateway bisa memproses pertukaran kode secara asinkron — pastikan
            // akunnya benar-benar aktif sebelum mengklaim "berhasil terhubung".
            if ($client->waitForAccountEnabled($provider)) {
                $client->forgetModelsCache();

                return redirect()->route('ai-providers.index')
                    ->with('success', $providers[$provider]['label'].' berhasil terhubung.');
            }

            session()->put("ai_provider_state.{$provider}", $state);

            return back()->with('error', 'Kode login diterima, tetapi akun belum aktif di gateway. Kemungkinan kode sudah dipakai atau kedaluwarsa — ulangi proses login dari awal dan tempel URL callback yang baru.');
        }

        session()->put("ai_provider_state.{$provider}", $state);

        return back()->with('error', 'Gagal menyelesaikan login. Kode mungkin kedaluwarsa — coba ulangi proses login dari awal.');
    }

    public function disconnect(string $provider, CliProxyApiClient $client): RedirectResponse
    {
        $providers = config('ai.providers', []);
        abort_unless(isset($providers[$provider]), 404);

        $account = $client->accountForProvider($provider);

        if ($account && isset($account['name']) && $client->disableAccount($account['name'])) {
            // Clean slate: hapus sisa status OAuth yang tertunda agar
            // penghubungan ulang ke provider yang sama mulai dari nol.
            // (Cache katalog model sudah dihapus di dalam disableAccount.)
            session()->forget("ai_provider_state.{$provider}");

            return redirect()->route('ai-providers.index')
                ->with('success', $providers[$provider]['label'].' diputus. Kamu bisa mengaktifkannya lagi kapan pun tanpa login ulang.');
        }

        return redirect()->route('ai-providers.index')
            ->with('error', 'Gagal memutus akun. Coba lagi.');
    }
}
