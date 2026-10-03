<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

final class ApiMetrics
{
    public const array OPERATIONS = ['activate', 'refresh', 'create', 'renew', 'other'];
    public const array BUCKETS = [0.005, 0.025, 0.1, 0.5, 1, 5];
    public function __construct(#[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache, private readonly LockFactory $locks) {}

    public function record(string $operation, int $status, float $seconds): void
    {
        if (!in_array($operation, self::OPERATIONS, true)) { $operation = 'other'; }
        $class = match (true) { $status >= 500 => '5xx', $status >= 400 => '4xx', $status >= 300 => '3xx', default => '2xx' };
        $lock = $this->locks->createLock('operations.api-metrics', 30);
        if (!$lock->acquire(true)) { return; }
        try {
            $item = $this->cache->getItem('license_api_metrics_v1'); $data = $item->get() ?? [];
            $key = $operation.':'.$class;
            $data['requests'][$key] = ($data['requests'][$key] ?? 0) + 1;
            $data['duration'][$operation]['sum'] = ($data['duration'][$operation]['sum'] ?? 0) + max(0, $seconds);
            $data['duration'][$operation]['count'] = ($data['duration'][$operation]['count'] ?? 0) + 1;
            foreach (self::BUCKETS as $index => $bound) { if ($seconds <= $bound) { $data['duration'][$operation]['buckets'][$index] = ($data['duration'][$operation]['buckets'][$index] ?? 0) + 1; } }
            if ($status === 429) { $data['rate_limits'] = ($data['rate_limits'] ?? 0) + 1; }
            if ($operation === 'refresh' && $status >= 400) { $data['refresh_failures'] = ($data['refresh_failures'] ?? 0) + 1; }
            $item->set($data); $this->cache->save($item);
        } finally { $lock->release(); }
    }

    public function exposition(): string
    {
        $data = $this->cache->getItem('license_api_metrics_v1')->get() ?? [];
        $lines = ['# TYPE license_api_requests_total counter', '# TYPE license_api_duration_seconds histogram'];
        foreach (self::OPERATIONS as $operation) {
            foreach (['2xx', '3xx', '4xx', '5xx'] as $class) { $lines[] = 'license_api_requests_total{operation="'.$operation.'",status="'.$class.'"} '.($data['requests'][$operation.':'.$class] ?? 0); }
            foreach (self::BUCKETS as $index => $bound) { $lines[] = 'license_api_duration_seconds_bucket{operation="'.$operation.'",le="'.$bound.'"} '.($data['duration'][$operation]['buckets'][$index] ?? 0); }
            $lines[] = 'license_api_duration_seconds_bucket{operation="'.$operation.'",le="+Inf"} '.($data['duration'][$operation]['count'] ?? 0);
            $lines[] = 'license_api_duration_seconds_count{operation="'.$operation.'"} '.($data['duration'][$operation]['count'] ?? 0);
            $lines[] = 'license_api_duration_seconds_sum{operation="'.$operation.'"} '.($data['duration'][$operation]['sum'] ?? 0);
        }
        $lines[] = 'license_api_rate_limits_total '.($data['rate_limits'] ?? 0);
        $lines[] = 'license_api_refresh_failures_total '.($data['refresh_failures'] ?? 0);
        return implode("\n", $lines)."\n";
    }
}
