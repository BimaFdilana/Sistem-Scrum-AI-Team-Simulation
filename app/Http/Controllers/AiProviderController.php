<?php

namespace App\Http\Controllers;

use App\Services\CliProxyApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiProviderController extends Controller
{
    public function index(CliProxyApiClient $client): View
    {
        $providers = config('ai.providers', []);
        $accounts = $client->accounts()['files'] ?? [];
        $gatewayUp = $client->isAvailable();
        $managementUp = $client->isManagementAvailable();

        $statuses = [];
        foreach (array_keys($providers) as $slug) {
            $statuses[$slug] = $this->matchAccount($accounts, $slug);
        }

        return view('workspace.ai-providers', [
            'providers' => $providers,
            'statuses' => $statuses,
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

        $request->validate([
            'callback_url' => ['required', 'string', 'max:4000'],
        ], [
            'callback_url.required' => 'Tempel URL callback dari address bar browser kamu.',
        ]);

        $state = session()->pull("ai_provider_state.{$provider}");

        if (! $state) {
            return redirect()->route('ai-providers.connect', ['provider' => $provider])
                ->with('error', 'Sesi login kedaluwarsa. Silakan mulai lagi dari tombol Hubungkan.');
        }

        $code = CliProxyApiClient::extractCode($request->string('callback_url')->toString());

        if (! $code) {
            session()->put("ai_provider_state.{$provider}", $state);

            return back()->with('error', 'URL tersebut tidak mengandung kode otorisasi (parameter ?code=...). Pastikan kamu meng-copy seluruh URL dari address bar setelah menekan Allow/Izinkan.');
        }

        if ($client->submitCallback($state, $code)) {
            return redirect()->route('ai-providers.index')
                ->with('success', $providers[$provider]['label'].' berhasil terhubung.');
        }

        session()->put("ai_provider_state.{$provider}", $state);

        return back()->with('error', 'Gagal menyelesaikan login. Kode mungkin kedaluwarsa — coba ulangi proses login dari awal.');
    }

    public function disconnect(string $provider, CliProxyApiClient $client): RedirectResponse
    {
        $providers = config('ai.providers', []);
        abort_unless(isset($providers[$provider]), 404);

        $accounts = $client->accounts()['files'] ?? [];
        $account = $this->matchAccount($accounts, $provider);

        if ($account && isset($account['name']) && $client->disableAccount($account['name'])) {
            return redirect()->route('ai-providers.index')
                ->with('success', $providers[$provider]['label'].' diputus.');
        }

        return redirect()->route('ai-providers.index')
            ->with('error', 'Gagal memutus akun. Coba lagi.');
    }

    /**
     * Find the linked account file belonging to a provider slug.
     */
    protected function matchAccount(array $accounts, string $provider): ?array
    {
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $haystack = implode(' ', array_filter([
                $account['provider'] ?? null,
                $account['type'] ?? null,
                $account['kind'] ?? null,
                $account['name'] ?? null,
                $account['id'] ?? null,
            ]));

            if ($haystack !== '' && stripos($haystack, $provider) !== false) {
                return $account;
            }
        }

        return null;
    }
}
