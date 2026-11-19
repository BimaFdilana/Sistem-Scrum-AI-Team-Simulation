<?php

namespace App\Support\System;

use Illuminate\Support\Facades\Cache;

/**
 * Pemakaian sumber daya VPS untuk halaman AI Model: disk, RAM, CPU, dan lalu
 * lintas jaringan. Dibaca langsung dari /proc tanpa alat tambahan.
 *
 * Persentase CPU dan kecepatan jaringan butuh dua sampel. Sampel terakhir
 * disimpan di cache; bila belum ada atau sudah basi, diambil dua sampel
 * berjarak singkat dalam satu permintaan.
 */
class ServerMetrics
{
    private const SAMPLE_CACHE_KEY = 'server-metrics:sample';

    private const SAMPLE_MAX_AGE = 60;

    private const SAMPLE_GAP_MICROSECONDS = 300_000;

    public function __construct(private string $procRoot = '/proc') {}

    /**
     * @return array{disk: ?array{total: int, used: int, free: int, percent: float}, memory: ?array{total: int, used: int, available: int, percent: float, swap_total: int, swap_used: int}, cpu: array{cores: int, percent: ?float, load: ?array{0: float, 1: float, 2: float}}, network: ?array{interface: string, rx_rate: float, tx_rate: float, rx_total: int, tx_total: int}, uptime: ?int, sampled_at: string}
     */
    public function snapshot(): array
    {
        [$previous, $current] = $this->samples();

        return [
            'disk' => $this->disk(),
            'memory' => $this->memory(),
            'cpu' => [
                'cores' => $this->cores(),
                'percent' => $previous && $current['cpu'] && $previous['cpu'] ? self::cpuPercent($previous['cpu'], $current['cpu']) : null,
                'load' => self::parseLoadAverage($this->read('loadavg')),
            ],
            'network' => $this->network($previous, $current),
            'uptime' => self::parseUptime($this->read('uptime')),
            'sampled_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{total: int, used: int, free: int, percent: float}|null */
    public function disk(): ?array
    {
        $total = @disk_total_space(base_path());
        $free = @disk_free_space(base_path());
        if (! is_float($total) || ! is_float($free) || $total <= 0) {
            return null;
        }
        $used = $total - $free;

        return ['total' => (int) $total, 'used' => (int) $used, 'free' => (int) $free, 'percent' => round($used / $total * 100, 1)];
    }

    /** @return array{total: int, used: int, available: int, percent: float, swap_total: int, swap_used: int}|null */
    public function memory(): ?array
    {
        return self::parseMeminfo($this->read('meminfo'));
    }

    /**
     * @return array{total: int, used: int, available: int, percent: float, swap_total: int, swap_used: int}|null
     */
    public static function parseMeminfo(?string $meminfo): ?array
    {
        if ($meminfo === null || ! preg_match_all('/^(\w+):\s+(\d+)\s*kB/m', $meminfo, $matches)) {
            return null;
        }
        $values = array_map(fn (string $kb): int => (int) $kb * 1024, array_combine($matches[1], $matches[2]));
        $total = $values['MemTotal'] ?? 0;
        if ($total <= 0) {
            return null;
        }
        $available = $values['MemAvailable'] ?? (($values['MemFree'] ?? 0) + ($values['Cached'] ?? 0) + ($values['Buffers'] ?? 0));
        $swapTotal = $values['SwapTotal'] ?? 0;

        return [
            'total' => $total,
            'used' => $total - $available,
            'available' => $available,
            'percent' => round(($total - $available) / $total * 100, 1),
            'swap_total' => $swapTotal,
            'swap_used' => $swapTotal - ($values['SwapFree'] ?? $swapTotal),
        ];
    }

    /**
     * Baris `cpu` agregat dari /proc/stat.
     *
     * @return array{idle: int, total: int}|null
     */
    public static function parseCpuStat(?string $stat): ?array
    {
        if ($stat === null || ! preg_match('/^cpu\s+([\d\s]+)$/m', $stat, $match)) {
            return null;
        }
        $fields = array_map('intval', preg_split('/\s+/', trim($match[1])) ?: []);
        if (count($fields) < 4) {
            return null;
        }
        // user nice system idle iowait irq softirq steal (guest sudah termasuk di user/nice).
        $fields = array_slice($fields, 0, 8);

        return ['idle' => $fields[3] + ($fields[4] ?? 0), 'total' => array_sum($fields)];
    }

    /**
     * @param  array{idle: int, total: int}  $previous
     * @param  array{idle: int, total: int}  $current
     */
    public static function cpuPercent(array $previous, array $current): ?float
    {
        $total = $current['total'] - $previous['total'];
        if ($total <= 0) {
            return null;
        }

        return round(max(0, min(100, (1 - ($current['idle'] - $previous['idle']) / $total) * 100)), 1);
    }

    /** @return array{0: float, 1: float, 2: float}|null */
    public static function parseLoadAverage(?string $loadavg): ?array
    {
        if ($loadavg === null || ! preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', trim($loadavg), $match)) {
            return null;
        }

        return [(float) $match[1], (float) $match[2], (float) $match[3]];
    }

    public static function parseUptime(?string $uptime): ?int
    {
        return $uptime !== null && preg_match('/^([\d.]+)/', trim($uptime), $match) ? (int) $match[1] : null;
    }

    /** Antarmuka rute default (tujuan 00000000) dari /proc/net/route. */
    public static function defaultInterface(?string $route): ?string
    {
        foreach (preg_split('/\R/', (string) $route) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) > 2 && $parts[1] === '00000000' && $parts[0] !== 'Iface') {
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * Total byte terima/kirim per antarmuka dari /proc/net/dev.
     *
     * @return array<string, array{rx: int, tx: int}>
     */
    public static function parseNetDev(?string $netDev): array
    {
        $interfaces = [];
        foreach (preg_split('/\R/', (string) $netDev) ?: [] as $line) {
            if (! preg_match('/^\s*([^:\s]+):\s*(.+)$/', $line, $match)) {
                continue;
            }
            $fields = preg_split('/\s+/', trim($match[2])) ?: [];
            if (count($fields) >= 9) {
                $interfaces[$match[1]] = ['rx' => (int) $fields[0], 'tx' => (int) $fields[8]];
            }
        }

        return $interfaces;
    }

    /**
     * @param  array{at: float, cpu: ?array, net: ?array}|null  $previous
     * @param  array{at: float, cpu: ?array, net: ?array}  $current
     * @return array{interface: string, rx_rate: float, tx_rate: float, rx_total: int, tx_total: int}|null
     */
    private function network(?array $previous, array $current): ?array
    {
        $net = $current['net'];
        if ($net === null) {
            return null;
        }
        $seconds = $previous ? $current['at'] - $previous['at'] : 0;
        $sameInterface = $previous && ($previous['net']['interface'] ?? null) === $net['interface'];
        $rate = fn (string $key): float => $sameInterface && $seconds > 0
            ? round(max(0, $net[$key] - $previous['net'][$key]) / $seconds, 1)
            : 0.0;

        return [
            'interface' => $net['interface'],
            'rx_rate' => $rate('rx'),
            'tx_rate' => $rate('tx'),
            'rx_total' => $net['rx'],
            'tx_total' => $net['tx'],
        ];
    }

    /**
     * @return array{0: ?array{at: float, cpu: ?array, net: ?array}, 1: array{at: float, cpu: ?array, net: ?array}}
     */
    private function samples(): array
    {
        $previous = Cache::get(self::SAMPLE_CACHE_KEY);
        $now = microtime(true);
        if (! is_array($previous) || $now - (float) ($previous['at'] ?? 0) > self::SAMPLE_MAX_AGE || $now - (float) ($previous['at'] ?? 0) < 0.2) {
            $previous = $this->sample();
            usleep(self::SAMPLE_GAP_MICROSECONDS);
        }
        $current = $this->sample();
        Cache::put(self::SAMPLE_CACHE_KEY, $current, self::SAMPLE_MAX_AGE * 2);

        return [$previous, $current];
    }

    /** @return array{at: float, cpu: ?array{idle: int, total: int}, net: ?array{interface: string, rx: int, tx: int}} */
    private function sample(): array
    {
        $interface = self::defaultInterface($this->read('net/route'));
        $counters = self::parseNetDev($this->read('net/dev'));
        if ($interface === null || ! isset($counters[$interface])) {
            $interface = collect(array_keys($counters))->first(fn (string $name): bool => $name !== 'lo');
        }

        return [
            'at' => microtime(true),
            'cpu' => self::parseCpuStat($this->read('stat')),
            'net' => $interface !== null && isset($counters[$interface]) ? ['interface' => $interface] + $counters[$interface] : null,
        ];
    }

    private function cores(): int
    {
        return max(1, preg_match_all('/^processor\s*:/m', (string) $this->read('cpuinfo')));
    }

    private function read(string $file): ?string
    {
        $content = @file_get_contents($this->procRoot.'/'.$file);

        return is_string($content) ? $content : null;
    }
}
