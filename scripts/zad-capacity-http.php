<?php

declare(strict_types=1);

if (! extension_loaded('curl')) {
    fwrite(STDERR, "The PHP cURL extension is required.\n");
    exit(2);
}

$options = getopt('', ['base-url:', 'fixtures:', 'clients::', 'concurrency::', 'output::', 'allow-staging']);
$baseUrl = rtrim((string) ($options['base-url'] ?? ''), '/');
$fixturePath = (string) ($options['fixtures'] ?? dirname(__DIR__).'/storage/app/private/capacity/fixtures.json');
$clientCount = max(1, min(1000, (int) ($options['clients'] ?? 1000)));
$concurrency = max(1, min(1000, (int) ($options['concurrency'] ?? 100)));
$output = (string) ($options['output'] ?? dirname(__DIR__).'/storage/app/private/capacity/load-result.json');
$host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
$safeLocal = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
$safeStaging = isset($options['allow-staging']) && preg_match('/(staging|stage|test|qa|dev)/', $host) === 1;

if ($baseUrl === '' || (! $safeLocal && ! $safeStaging)) {
    fwrite(STDERR, "Refusing to run against a non-local/non-staging URL. Use localhost, or a test hostname with --allow-staging.\n");
    exit(3);
}

$fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$clients = array_slice((array) ($fixture['clients'] ?? []), 0, $clientCount);

if (count($clients) < $clientCount) {
    fwrite(STDERR, "Fixture file contains fewer clients than requested.\n");
    exit(4);
}

/** @return array<int, array<string, mixed>> */
function runBatch(array $requests, int $concurrency): array
{
    $results = [];
    $queue = array_values($requests);
    $multi = curl_multi_init();
    $active = [];

    $add = static function (array $request) use ($multi, &$active): void {
        $handle = curl_init($request['url']);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request['body'], JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        curl_multi_add_handle($multi, $handle);
        $active[(int) $handle] = ['handle' => $handle, 'request' => $request, 'started' => microtime(true)];
    };

    while ($queue !== [] || $active !== []) {
        while ($queue !== [] && count($active) < $concurrency) {
            $add(array_shift($queue));
        }

        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($multi)) {
            $handle = $info['handle'];
            $state = $active[(int) $handle];
            $body = (string) curl_multi_getcontent($handle);
            $results[] = [
                'client' => $state['request']['client'],
                'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'seconds' => microtime(true) - $state['started'],
                'body' => json_decode($body, true) ?: $body,
                'error' => curl_error($handle),
            ];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            unset($active[(int) $handle]);
        }

        if ($running > 0) {
            curl_multi_select($multi, 0.25);
        }
    }

    curl_multi_close($multi);

    return $results;
}

function percentile(array $values, float $percentile): float
{
    if ($values === []) return 0.0;
    sort($values, SORT_NUMERIC);
    $index = (int) ceil(($percentile / 100) * count($values)) - 1;
    return round((float) $values[max(0, min($index, count($values) - 1))], 4);
}

$trackingRequests = array_map(static fn (array $client): array => [
    'client' => $client['client'],
    'url' => $baseUrl.'/api/v1/app/launch/track',
    'body' => [
        'event_id' => 'capacity-visit-'.$client['client'].'-'.bin2hex(random_bytes(5)),
        'event_type' => 'visit',
        'source' => 'capacity-test',
        'medium' => 'load-test',
        'app_guest_session_id' => $client['payload']['guest_session_id'],
    ],
], $clients);

$started = microtime(true);
$trackingResults = runBatch($trackingRequests, $concurrency);
$sessions = [];

foreach ($trackingResults as $result) {
    if (is_array($result['body']) && isset($result['body']['data']['session_token'])) {
        $sessions[$result['client']] = $result['body']['data']['session_token'];
    }
}

$orderRequests = array_map(static function (array $client) use ($baseUrl, $sessions): array {
    $payload = $client['payload'];
    if (isset($sessions[$client['client']])) {
        $payload['launch_session_token'] = $sessions[$client['client']];
    }
    return ['client' => $client['client'], 'url' => $baseUrl.'/api/v1/app/orders', 'body' => $payload];
}, $clients);
$orderResults = runBatch($orderRequests, $concurrency);
$elapsed = microtime(true) - $started;
$allResults = [...$trackingResults, ...$orderResults];
$latencies = array_column($allResults, 'seconds');
$success = count(array_filter($allResults, static fn (array $row): bool => $row['status'] >= 200 && $row['status'] < 300));
$orderSuccess = count(array_filter($orderResults, static fn (array $row): bool => $row['status'] === 201));
$report = [
    'generated_at' => date(DATE_ATOM),
    'base_url' => $baseUrl,
    'clients' => $clientCount,
    'concurrency' => $concurrency,
    'requests' => count($allResults),
    'successful_requests' => $success,
    'failed_requests' => count($allResults) - $success,
    'successful_orders' => $orderSuccess,
    'elapsed_seconds' => round($elapsed, 3),
    'throughput_requests_per_second' => round(count($allResults) / max($elapsed, 0.001), 2),
    'latency_seconds' => [
        'p50' => percentile($latencies, 50),
        'p95' => percentile($latencies, 95),
        'p99' => percentile($latencies, 99),
        'max' => round(max($latencies ?: [0]), 4),
    ],
    'status_counts' => array_count_values(array_map(static fn (array $row): string => (string) $row['status'], $allResults)),
    'errors' => array_values(array_slice(array_filter($allResults, static fn (array $row): bool => $row['status'] < 200 || $row['status'] >= 300), 0, 50)),
];

if (! is_dir(dirname($output))) mkdir(dirname($output), 0775, true);
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
exit($report['failed_requests'] > 0 ? 1 : 0);
