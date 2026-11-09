<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Uji jalan nyata untuk project Laravel yang dikerjakan tim AI.
 *
 * Dijalankan oleh ScrumSpace sebagai proses terpisah DI DALAM project target
 * (bukan di ScrumSpace): `php probe.php <config.json>`. Project di-boot dengan
 * database salinan, masuk sebagai admin, lalu setiap halaman yang dipilih
 * dibuka lewat HTTP kernel project itu sendiri. Form yang ditemukan di halaman
 * dikirim dengan data contoh. Hasilnya JSON di stdout (diawali penanda).
 *
 * Config: {"root": "...", "mode": "changed"|"all", "files": ["app/Http/Controllers/X.php", ...]}
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 'stderr');
ini_set('memory_limit', '512M');

const SMOKE_MARKER = '@@SCRUMSPACE_SMOKE@@';

$config = json_decode((string) file_get_contents($argv[1] ?? ''), true);
if (! is_array($config) || empty($config['root'])) {
    fwrite(STDERR, "config tidak valid\n");
    exit(2);
}

$root = rtrim((string) $config['root'], '/');
$mode = ($config['mode'] ?? 'changed') === 'all' ? 'all' : 'changed';
$changedFiles = array_values(array_map(fn ($f) => ltrim((string) $f, '/'), (array) ($config['files'] ?? [])));

chdir($root);
require $root.'/vendor/autoload.php';

// vendor/ bisa berupa tautan ke salinan project lain (audit cabang di worktree sementara):
// kelas project sendiri harus dimuat dari root ini, bukan dari lokasi asli vendor.
$composer = json_decode((string) @file_get_contents($root.'/composer.json'), true);
$prefixes = (array) ($composer['autoload']['psr-4'] ?? []);
spl_autoload_register(function (string $class) use ($prefixes, $root): void {
    foreach ($prefixes as $prefix => $dirs) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }
        foreach ((array) $dirs as $dir) {
            $file = $root.'/'.rtrim($dir, '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}, true, true);
$app = require $root.'/bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$app->instance('request', Request::create('/'));

// Aset Vite tidak relevan untuk uji jalan; tanpa manifest, @vite melempar exception.
$app->instance(Vite::class, new class extends Vite
{
    public function __invoke($entrypoints, $buildDirectory = null)
    {
        return new HtmlString('');
    }

    public function asset($asset, $buildDirectory = null)
    {
        return '';
    }

    public function content($asset, $buildDirectory = null)
    {
        return '';
    }

    public function reactRefresh()
    {
        return '';
    }
});

$results = [];
$report = function (array $entry) use (&$results): void {
    $results[] = $entry;
};

$relative = function (?string $path) use ($root): ?string {
    if ($path === null || $path === '') {
        return null;
    }

    return str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
};

/**
 * Pengguna penguji di database salinan.
 *
 * Halaman sering dibatasi peran (admin, verifikator, ...). Nilai peran dibaca
 * dari kode project sendiri, lalu untuk tiap peran dibuat satu pengguna uji di
 * database SALINAN, supaya halaman yang dibatasi tetap bisa dibuka.
 *
 * @var array<int, array{user: mixed, role: ?string}> $actors
 */
$actors = [];
$userModel = config('auth.providers.users.model');
if (is_string($userModel) && class_exists($userModel)) {
    try {
        $base = $userModel::query()->orderBy((new $userModel)->getKeyName())->first();
        if ($base !== null) {
            $columns = Schema::getColumnListing($base->getTable());
            $roleColumn = null;
            foreach (['role', 'peran', 'level', 'jabatan', 'tipe', 'type', 'user_type', 'role_name'] as $column) {
                if (in_array($column, $columns, true)) {
                    $roleColumn = $column;
                    break;
                }
            }

            $actors[] = ['user' => $base, 'role' => $roleColumn ? (string) $base->getAttribute($roleColumn) : null];

            if ($roleColumn !== null) {
                $roles = ['admin'];
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
                $sources = [];
                foreach ($iterator as $file) {
                    if (str_ends_with($file->getFilename(), '.php')) {
                        $sources[] = $file->getPathname();
                    }
                }
                foreach (glob($root.'/routes/*.php') ?: [] as $file) {
                    $sources[] = $file;
                }
                $quoted = preg_quote($roleColumn, '/');
                foreach ($sources as $file) {
                    $code = (string) @file_get_contents($file);
                    if (preg_match_all('/'.$quoted.'[\'"]?\]?\s*(?:===?|!==?)\s*[\'"]([^\'"]{1,40})[\'"]/i', $code, $m)) {
                        array_push($roles, ...$m[1]);
                    }
                    if (preg_match_all('/[\'"]role:([^\'"]{1,80})[\'"]/i', $code, $m)) {
                        foreach ($m[1] as $list) {
                            array_push($roles, ...preg_split('/[,|]/', $list));
                        }
                    }
                }
                $existing = strtolower((string) $base->getAttribute($roleColumn));
                $seen = [$existing => true];
                foreach ($roles as $role) {
                    $role = trim($role);
                    if ($role === '' || isset($seen[strtolower($role)]) || count($actors) >= 8) {
                        continue;
                    }
                    $seen[strtolower($role)] = true;
                    $clone = $base->replicate();
                    if (in_array('email', $columns, true)) {
                        $clone->setAttribute('email', 'uji-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($role)).'-'.random_int(1000, 9999).'@example.com');
                    }
                    if (in_array('username', $columns, true)) {
                        $clone->setAttribute('username', 'uji_'.random_int(10000, 99999));
                    }
                    $clone->setAttribute($roleColumn, $role);
                    $clone->saveQuietly();
                    $actors[] = ['user' => $clone, 'role' => $role];
                }
                // Admin dicoba lebih dulu: paling banyak halaman yang terbuka untuknya.
                usort($actors, fn ($a, $b) => (int) ! preg_match('/admin/i', (string) $a['role']) <=> (int) ! preg_match('/admin/i', (string) $b['role']));
            }
        }
    } catch (Throwable $e) {
        $report(['kind' => 'setup', 'method' => '-', 'uri' => '-', 'status' => null, 'error' => 'Tidak bisa menyiapkan pengguna uji: '.mb_substr($e->getMessage(), 0, 300)]);
    }
}
$user = $actors[0]['user'] ?? null;
if ($user !== null) {
    $app['auth']->guard()->setUser($user);
}

/** Lokasi kesalahan: frame pertama di dalam project (bukan vendor). */
$locate = function (Throwable $e) use ($root, $relative): array {
    $chain = [];
    for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
        $chain[] = $cursor;
    }
    $origin = end($chain) ?: $e;
    $message = $origin->getMessage();

    if (preg_match('/\(View: ([^)]+)\)/', $e->getMessage(), $m)) {
        return ['file' => $relative(trim($m[1])), 'line' => null, 'message' => $message];
    }

    $frames = array_merge([['file' => $origin->getFile(), 'line' => $origin->getLine()]], $origin->getTrace());
    foreach ($frames as $frame) {
        $file = $frame['file'] ?? '';
        if ($file !== '' && str_starts_with($file, $root.'/') && ! str_contains($file, '/vendor/') && ! str_contains($file, '/storage/framework/')) {
            return ['file' => $relative($file), 'line' => $frame['line'] ?? null, 'message' => $message];
        }
    }

    return ['file' => null, 'line' => null, 'message' => $message];
};

$session = fn () => $app['session']->driver();

/**
 * Kirim satu permintaan lewat kernel project.
 *
 * @return array{status: int, html: string, errors: array<int, string>, exception: ?Throwable, location: ?string}
 */
$send = function (string $method, string $uri, array $data = []) use ($app, $kernel, $session, &$user): array {
    try {
        $session()->flush();
    } catch (Throwable) {
    }
    if ($user !== null) {
        $app['auth']->guard()->setUser($user);
    }

    $request = Request::create($uri, $method, $data);
    $request->headers->set('Accept', 'text/html');
    $exception = null;

    try {
        $response = $kernel->handle($request);
        $exception = $response->exception ?? null;
        $status = $response->getStatusCode();
        $html = method_exists($response, 'getContent') ? (string) $response->getContent() : '';
        $location = $response->headers->get('Location');
        $kernel->terminate($request, $response);
    } catch (Throwable $e) {
        $exception = $e;
        $status = 500;
        $html = '';
        $location = null;
    }

    $errors = [];
    try {
        $bag = $session()->get('errors');
        if ($bag instanceof ViewErrorBag) {
            $errors = $bag->getBag('default')->all();
        }
    } catch (Throwable) {
    }

    return ['status' => $status, 'html' => $html, 'errors' => $errors, 'exception' => $exception, 'location' => $location];
};

/** Nilai contoh untuk satu field form. */
$sample = function (string $name, string $type, array $attrs) {
    $lower = strtolower($name);
    $suffix = substr((string) random_int(100000, 999999), 0, 6);
    $min = isset($attrs['min']) && is_numeric($attrs['min']) ? (float) $attrs['min'] : null;
    $max = isset($attrs['max']) && is_numeric($attrs['max']) ? (float) $attrs['max'] : null;

    $value = match (true) {
        $type === 'email' || str_contains($lower, 'email') => 'uji'.$suffix.'@example.com',
        $type === 'password' || str_contains($lower, 'password') => 'Password123!',
        $type === 'date' => date('Y-m-d'),
        $type === 'datetime-local' => date('Y-m-d\TH:i'),
        $type === 'time' => date('H:i'),
        $type === 'month' => date('Y-m'),
        $type === 'url' => 'https://example.com',
        $type === 'tel' || str_contains($lower, 'hp') || str_contains($lower, 'phone') || str_contains($lower, 'telp') => '081234567890',
        $type === 'number' || $type === 'range' => (string) (str_contains($lower, 'tahun') || str_contains($lower, 'year')
            ? max((int) ($min ?? 0), min((int) ($max ?? 9999), (int) date('Y')))
            : (int) max($min ?? 1, min($max ?? 100, 10))),
        str_contains($lower, 'tahun') || str_contains($lower, 'year') => date('Y'),
        str_contains($lower, 'kode') || str_contains($lower, 'code') => 'UJI-'.$suffix,
        str_contains($lower, 'nip') || str_contains($lower, 'nik') => '19800101'.$suffix,
        default => 'Uji otomatis '.$suffix,
    };

    if (isset($attrs['maxlength']) && is_numeric($attrs['maxlength']) && (int) $attrs['maxlength'] > 0) {
        $value = mb_substr($value, 0, (int) $attrs['maxlength']);
    }

    return $value;
};

/**
 * Form di halaman yang mengarah ke rute project (POST/PUT/PATCH).
 *
 * @return array<int, array{method: string, action: string, data: array<string, mixed>}>
 */
$formsIn = function (string $html, string $pageUri) use ($sample): array {
    if (trim($html) === '' || stripos($html, '<form') === false) {
        return [];
    }

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $forms = [];

    foreach ($dom->getElementsByTagName('form') as $form) {
        $method = strtoupper($form->getAttribute('method') ?: 'GET');
        if ($method !== 'POST') {
            continue;
        }

        $action = $form->getAttribute('action') ?: $pageUri;
        $path = parse_url($action, PHP_URL_PATH) ?: '/';
        if (preg_match('#/(logout|keluar)$#i', $path)) {
            continue;
        }

        $data = [];
        $spoof = 'POST';
        $radios = [];
        $checkboxSeen = [];

        foreach ((new DOMXPath($dom))->query('.//input|.//select|.//textarea', $form) as $field) {
            $name = $field->getAttribute('name');
            if ($name === '' || $field->hasAttribute('disabled')) {
                continue;
            }
            $tag = strtolower($field->nodeName);
            $type = strtolower($field->getAttribute('type') ?: ($tag === 'input' ? 'text' : $tag));
            $attrs = ['min' => $field->getAttribute('min'), 'max' => $field->getAttribute('max'), 'maxlength' => $field->getAttribute('maxlength')];
            $current = $field->getAttribute('value');

            if ($name === '_method') {
                $spoof = strtoupper($current ?: 'POST');

                continue;
            }
            if ($name === '_token' || in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) {
                continue;
            }

            if ($tag === 'select') {
                $chosen = null;
                $firstFilled = null;
                foreach ($field->getElementsByTagName('option') as $option) {
                    $value = $option->hasAttribute('value') ? $option->getAttribute('value') : trim($option->textContent);
                    if ($option->hasAttribute('selected') && $value !== '') {
                        $chosen = $value;
                    }
                    if ($firstFilled === null && $value !== '' && ! $option->hasAttribute('disabled')) {
                        $firstFilled = $value;
                    }
                }
                $value = $chosen ?? $firstFilled ?? '';
            } elseif ($type === 'radio') {
                if (isset($radios[$name]) && ! $field->hasAttribute('checked')) {
                    continue;
                }
                $radios[$name] = true;
                $value = $current;
            } elseif ($type === 'checkbox') {
                if (! $field->hasAttribute('checked') && isset($checkboxSeen[$name])) {
                    continue;
                }
                $checkboxSeen[$name] = true;
                $value = $current !== '' ? $current : '1';
            } elseif ($type === 'hidden') {
                $value = $current;
            } elseif ($tag === 'textarea') {
                $value = trim($field->textContent) !== '' ? $field->textContent : 'Uji otomatis isi teks';
            } else {
                $value = $current !== '' ? $current : $sample($name, $type, $attrs);
            }

            if (str_ends_with($name, '[]')) {
                $data[substr($name, 0, -2)][] = $value;
            } elseif (preg_match('/^([^\[]+)\[([^\]]*)\]$/', $name, $m)) {
                $data[$m[1]][$m[2]] = $value;
            } else {
                $data[$name] = $value;
            }

            if (str_ends_with($name, 'password') && ! array_key_exists($name.'_confirmation', $data)) {
                $data[$name.'_confirmation'] = $value;
            }
        }

        if (in_array($spoof, ['DELETE'], true)) {
            continue;
        }

        $forms[] = ['method' => $spoof, 'action' => $path, 'data' => $data];
    }

    return $forms;
};

/** Pilih rute yang diuji. */
$router = $app['router'];
$routes = [];
foreach ($router->getRoutes()->getRoutes() as $route) {
    $action = $route->getAction();
    $uses = $action['uses'] ?? null;
    $file = null;
    $methodName = null;
    $controller = null;

    if (is_string($uses) && str_contains($uses, '@')) {
        [$controller, $methodName] = explode('@', $uses, 2);
        try {
            $file = $relative((new ReflectionClass($controller))->getFileName() ?: null);
        } catch (Throwable) {
            $file = null;
        }
    } elseif ($uses instanceof Closure) {
        try {
            $file = $relative((new ReflectionFunction($uses))->getFileName() ?: null);
        } catch (Throwable) {
            $file = null;
        }
    }

    if ($file === null || str_starts_with($file, 'vendor/') || str_starts_with($file, '/')) {
        continue;
    }

    $methods = array_diff($route->methods(), ['HEAD']);
    if (! in_array('GET', $methods, true)) {
        continue; // POST/PUT diuji lewat form di halaman, bukan ditembak buta.
    }

    $uri = '/'.ltrim($route->uri(), '/');
    if (preg_match('#(^|/)(logout|keluar|_ignition|telescope|horizon|livewire|sanctum)#i', $uri)) {
        continue;
    }
    if (str_contains($uri, '{') && preg_match('#(verify|reset|token|hash|signature)#i', $uri)) {
        continue;
    }

    if ($mode === 'changed' && ! in_array($file, $changedFiles, true)) {
        continue;
    }

    $routes[] = ['route' => $route, 'uri' => $uri, 'controller' => $controller, 'method' => $methodName, 'file' => $file];
}

/** Isi parameter rute dari data yang ada di database salinan. */
$bind = function (array $item): ?string {
    $route = $item['route'];
    $uri = $item['uri'];
    if (! str_contains($uri, '{')) {
        return $uri;
    }

    $types = [];
    if ($item['controller'] && $item['method']) {
        try {
            foreach ((new ReflectionMethod($item['controller'], $item['method']))->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                    $types[$parameter->getName()] = $type->getName();
                }
            }
        } catch (Throwable) {
        }
    }

    foreach ($route->parameterNames() as $name) {
        $value = null;
        $class = $types[$name] ?? null;
        try {
            if ($class && is_subclass_of($class, Model::class)) {
                $model = new $class;
                $key = $route->bindingFieldFor($name) ?? $model->getRouteKeyName();
                $value = $class::query()->orderByDesc($model->getKeyName())->value($key);
            } else {
                $table = Str::plural(Str::snake($name));
                if (Schema::hasTable($table)) {
                    $value = DB::table($table)->orderByDesc('id')->value('id');
                } elseif (Schema::hasTable(Str::snake($name))) {
                    $value = DB::table(Str::snake($name))->orderByDesc('id')->value('id');
                }
            }
        } catch (Throwable) {
            $value = null;
        }

        if ($value === null) {
            if (preg_match('/\{'.preg_quote($name, '/').'\?\}/', $uri)) {
                $uri = str_replace('{'.$name.'?}', '', $uri);

                continue;
            }

            return null;
        }
        $uri = preg_replace('/\{'.preg_quote($name, '/').'\??\}/', rawurlencode((string) $value), $uri);
    }

    return rtrim($uri, '/') ?: '/';
};

$record = function (string $kind, string $method, string $uri, array $response, array $extra = []) use ($locate, $report): void {
    $entry = ['kind' => $kind, 'method' => $method, 'uri' => $uri, 'status' => $response['status']] + $extra;
    if ($response['exception'] instanceof Throwable) {
        $where = $locate($response['exception']);
        $entry['error'] = get_class($response['exception']).': '.mb_substr($where['message'], 0, 500);
        $entry['file'] = $where['file'];
        $entry['line'] = $where['line'];
    }
    if ($response['errors'] !== []) {
        $entry['validation'] = array_slice($response['errors'], 0, 8);
    }
    $report($entry);
};

$classify = function (array $response): string {
    if ($response['exception'] instanceof Throwable && ! ($response['exception'] instanceof ValidationException)) {
        $status = $response['status'];
        if ($response['exception'] instanceof HttpExceptionInterface && $status < 500) {
            return $status === 404 ? 'not_found' : 'denied';
        }
        if ($response['exception'] instanceof AuthorizationException || $response['exception'] instanceof AuthenticationException) {
            return 'denied';
        }

        return 'crash';
    }
    if ($response['status'] >= 500) {
        return 'crash';
    }
    if (in_array($response['status'], [401, 403], true)) {
        return 'denied';
    }
    if ($response['status'] === 404) {
        return 'not_found';
    }

    return 'ok';
};

// 1) Halaman tanpa parameter dulu (daftar, tambah), lalu form di dalamnya,
//    lalu halaman berparameter (detail, ubah) yang datanya bisa jadi baru dibuat.
usort($routes, fn ($a, $b) => (int) str_contains($a['uri'], '{') <=> (int) str_contains($b['uri'], '{'));

$submitted = [];
$pagesDone = [];
foreach ($routes as $item) {
    $uri = $bind($item);
    if ($uri === null) {
        $report(['kind' => 'skipped', 'method' => 'GET', 'uri' => $item['uri'], 'status' => null, 'reason' => 'tidak ada data untuk parameter rute']);

        continue;
    }
    if (isset($pagesDone[$uri])) {
        continue;
    }
    $pagesDone[$uri] = true;

    $role = null;
    foreach ($actors === [] ? [['user' => null, 'role' => null]] : $actors as $actor) {
        $user = $actor['user'];
        $role = $actor['role'];
        $page = $send('GET', $uri);
        $kind = $classify($page);
        if ($kind !== 'denied') {
            break;
        }
    }
    $record($kind, 'GET', $uri, $page, ['route_file' => $item['file'], 'as_role' => $role]);
    if ($kind !== 'ok') {
        continue;
    }

    foreach ($formsIn($page['html'], $uri) as $form) {
        $key = $form['method'].' '.$form['action'];
        if (isset($submitted[$key])) {
            continue;
        }
        $submitted[$key] = true;

        try {
            $target = $app['router']->getRoutes()->match(Request::create($form['action'], $form['method']));
            $targetUses = $target->getAction()['uses'] ?? null;
            $targetFile = is_string($targetUses) && str_contains($targetUses, '@')
                ? $relative((new ReflectionClass(explode('@', $targetUses)[0]))->getFileName() ?: null)
                : null;
        } catch (Throwable) {
            $targetFile = null;
        }
        if ($mode === 'changed' && $targetFile !== null && ! in_array($targetFile, $changedFiles, true)) {
            continue;
        }

        $data = $form['data'];
        if ($form['method'] !== 'POST') {
            $data['_method'] = $form['method'];
        }
        $result = $send('POST', $form['action'], $data);
        $kind = $classify($result);
        if ($kind === 'ok' && $result['errors'] !== []) {
            $kind = 'validation';
        }
        $record($kind, $form['method'], $form['action'], $result, ['from_page' => $uri, 'route_file' => $targetFile, 'fields' => array_keys($form['data'])]);
    }
}

echo SMOKE_MARKER.json_encode([
    'roles' => array_map(fn ($a) => $a['role'], $actors),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE).SMOKE_MARKER;
