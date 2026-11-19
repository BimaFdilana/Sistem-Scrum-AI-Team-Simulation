<?php

namespace Tests\Unit;

use App\Support\System\ServerMetrics;
use PHPUnit\Framework\TestCase;

class ServerMetricsTest extends TestCase
{
    public function test_memory_uses_available_memory_and_swap(): void
    {
        $memory = ServerMetrics::parseMeminfo("MemTotal:        2000 kB\nMemFree:          100 kB\nMemAvailable:     500 kB\nSwapTotal:       1000 kB\nSwapFree:         750 kB\n");

        $this->assertSame(2000 * 1024, $memory['total']);
        $this->assertSame(1500 * 1024, $memory['used']);
        $this->assertSame(75.0, $memory['percent']);
        $this->assertSame(250 * 1024, $memory['swap_used']);
        $this->assertNull(ServerMetrics::parseMeminfo('rusak'));
    }

    public function test_cpu_percent_comes_from_the_difference_of_two_samples(): void
    {
        $before = ServerMetrics::parseCpuStat("cpu  100 0 100 700 100 0 0 0 0 0\ncpu0 100 0 100 700 100 0 0 0 0 0\n");
        $after = ServerMetrics::parseCpuStat("cpu  150 0 150 800 100 0 0 0 0 0\n");

        $this->assertSame(['idle' => 800, 'total' => 1000], $before);
        $this->assertSame(50.0, ServerMetrics::cpuPercent($before, $after));
        $this->assertNull(ServerMetrics::cpuPercent($after, $after));
    }

    public function test_network_counters_follow_the_default_route_interface(): void
    {
        $route = "Iface\tDestination\tGateway\tFlags\nenp5s0\t0000F40A\t00000000\t0001\nenp5s0\t00000000\t0100F40A\t0003\n";
        $dev = "Inter-|   Receive\n face |bytes packets errs drop fifo frame compressed multicast|bytes\n    lo: 500 5 0 0 0 0 0 0 500 5 0 0 0 0 0 0\nenp5s0: 123456 10 0 0 0 0 0 0 654321 9 0 0 0 0 0 0\n";

        $this->assertSame('enp5s0', ServerMetrics::defaultInterface($route));
        $this->assertSame(['rx' => 123456, 'tx' => 654321], ServerMetrics::parseNetDev($dev)['enp5s0']);
    }

    public function test_load_average_and_uptime_are_parsed(): void
    {
        $this->assertSame([0.1, 0.15, 0.17], ServerMetrics::parseLoadAverage("0.10 0.15 0.17 1/223 4105341\n"));
        $this->assertSame(86400, ServerMetrics::parseUptime("86400.52 80000.00\n"));
    }
}
