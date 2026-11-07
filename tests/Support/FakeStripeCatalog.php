<?php

namespace Rushing\Commerce\Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * A stateful Stripe transport for the plan catalog: it keeps Products and Prices in memory and answers the calls
 * {@see \Rushing\Commerce\Stripe\StripePlanCatalog} makes (product retrieve/search/create/update, price
 * retrieve/list-by-lookup-key/create/update), with Stripe's own rules that matter here: a Price's amount, currency
 * and interval are immutable, and `transfer_lookup_key` moves a lookup key off the Price that held it. The real
 * stripe-php object graph runs over it with no network and no key.
 */
class FakeStripeCatalog implements ClientInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $products = [];

    /** @var array<string, array<string, mixed>> */
    public array $prices = [];

    /** @var array<int, array{method: string, path: string, params: array, idempotency: ?string}> */
    public array $requests = [];

    /** When true, product search finds nothing (Stripe Search is eventually consistent; a fresh product may lag). */
    public bool $searchLags = false;

    private int $sequence = 0;

    /**
     * Stripe's idempotency replay: a POST with a key already seen returns the FIRST response verbatim (even if the
     * object has since changed, e.g. been archived), and the same key with different parameters is an error.
     *
     * @var array<string, array{params: array, response: array}>
     */
    private array $idempotent = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower((string) $method);
        $path = (string) parse_url((string) $absUrl, PHP_URL_PATH);
        parse_str((string) parse_url((string) $absUrl, PHP_URL_QUERY), $query);
        $params = array_merge($query, (array) $params);
        $idempotency = null;
        foreach ((array) $headers as $header) {
            if (stripos((string) $header, 'Idempotency-Key:') === 0) {
                $idempotency = trim(substr((string) $header, strlen('Idempotency-Key:')));
            }
        }
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params, 'idempotency' => $idempotency];

        if ($method === 'post' && $idempotency !== null && isset($this->idempotent[$idempotency])) {
            return $this->idempotent[$idempotency]['params'] === $params
                ? $this->idempotent[$idempotency]['response']
                : [json_encode(['error' => ['type' => 'idempotency_error', 'message' => 'Keys for idempotent requests can only be used with the same parameters they were first used with.']]), 400, []];
        }

        $response = $this->route($method, $path, $params);
        if ($method === 'post' && $idempotency !== null) {
            $this->idempotent[$idempotency] = ['params' => $params, 'response' => $response];
        }

        return $response;
    }

    /** Requests that created an object (POST to a collection), in order. */
    public function creates(): array
    {
        return array_values(array_filter($this->requests, fn (array $r) => $r['method'] === 'post' && in_array($r['path'], ['/v1/products', '/v1/prices'], true)));
    }

    private function route(string $method, string $path, array $params): array
    {
        return match (true) {
            $method === 'get' && $path === '/v1/products/search' => $this->ok($this->list(array_values(array_filter(
                $this->searchLags ? [] : $this->products,
                fn (array $p) => $this->matchesSearch($p, (string) ($params['query'] ?? '')),
            )), '/v1/products/search', 'search_result')),
            $method === 'post' && $path === '/v1/products' => $this->ok($this->createProduct($params)),
            $method === 'get' && preg_match('#^/v1/products/([^/]+)$#', $path, $m) === 1 => isset($this->products[$m[1]])
                ? $this->ok($this->products[$m[1]])
                : $this->missing('product', $m[1]),
            $method === 'post' && preg_match('#^/v1/products/([^/]+)$#', $path, $m) === 1 => isset($this->products[$m[1]])
                ? $this->ok($this->products[$m[1]] = $this->merge($this->products[$m[1]], $params))
                : $this->missing('product', $m[1]),
            $method === 'get' && $path === '/v1/prices' => $this->ok($this->list(array_values(array_filter(
                $this->prices,
                fn (array $p) => in_array($p['lookup_key'], (array) ($params['lookup_keys'] ?? []), true)
                    && (! isset($params['active']) || $p['active'] === filter_var($params['active'], FILTER_VALIDATE_BOOLEAN)),
            )), '/v1/prices')),
            $method === 'post' && $path === '/v1/prices' => $this->ok($this->createPrice($params)),
            $method === 'get' && preg_match('#^/v1/prices/([^/]+)$#', $path, $m) === 1 => isset($this->prices[$m[1]])
                ? $this->ok($this->prices[$m[1]])
                : $this->missing('price', $m[1]),
            $method === 'post' && preg_match('#^/v1/prices/([^/]+)$#', $path, $m) === 1 => isset($this->prices[$m[1]])
                ? $this->ok($this->updatePrice($m[1], $params))
                : $this->missing('price', $m[1]),
            default => [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => "unhandled {$method} {$path}"]]), 400, []],
        };
    }

    /** Requests that wrote (POST), in order, as `METHOD path`. */
    public function writes(): array
    {
        return array_values(array_map(
            fn (array $r) => strtoupper($r['method']).' '.$r['path'],
            array_filter($this->requests, fn (array $r) => $r['method'] === 'post'),
        ));
    }

    private function createProduct(array $params): array
    {
        $id = 'prod_fake_'.(++$this->sequence);

        return $this->products[$id] = [
            'id' => $id,
            'object' => 'product',
            'active' => true,
            'name' => $params['name'] ?? null,
            'description' => ($params['description'] ?? '') === '' ? null : $params['description'],
            'metadata' => (array) ($params['metadata'] ?? []),
        ];
    }

    private function createPrice(array $params): array
    {
        $key = $params['lookup_key'] ?? null;
        if ($key !== null) {
            foreach ($this->prices as $priceId => $price) {
                if ($price['lookup_key'] === $key) {
                    if (! filter_var($params['transfer_lookup_key'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        return $this->error("A price (`{$priceId}`) already uses that lookup key.");
                    }
                    $this->prices[$priceId]['lookup_key'] = null;
                }
            }
        }

        $id = 'price_fake_'.(++$this->sequence);

        return $this->prices[$id] = [
            'id' => $id,
            'object' => 'price',
            'active' => true,
            'product' => $params['product'],
            'currency' => strtolower((string) $params['currency']),
            'unit_amount' => (int) $params['unit_amount'],
            'recurring' => ['interval' => $params['recurring']['interval'] ?? null],
            'lookup_key' => $key,
            'metadata' => (array) ($params['metadata'] ?? []),
        ];
    }

    private function updatePrice(string $id, array $params): array
    {
        foreach (['unit_amount', 'currency', 'recurring', 'product'] as $immutable) {
            if (array_key_exists($immutable, $params)) {
                return $this->error("A price's {$immutable} cannot be updated.");
            }
        }
        if (array_key_exists('lookup_key', $params) && filter_var($params['transfer_lookup_key'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            foreach ($this->prices as $priceId => $price) {
                if ($priceId !== $id && $price['lookup_key'] === $params['lookup_key']) {
                    $this->prices[$priceId]['lookup_key'] = null;
                }
            }
        }
        unset($params['transfer_lookup_key']);
        if (array_key_exists('active', $params)) {
            $params['active'] = filter_var($params['active'], FILTER_VALIDATE_BOOLEAN);
        }

        return $this->prices[$id] = $this->merge($this->prices[$id], $params);
    }

    private function merge(array $object, array $params): array
    {
        foreach ($params as $key => $value) {
            if ($key === 'metadata') {
                $object['metadata'] = array_merge((array) $object['metadata'], (array) $value);
            } elseif ($key === 'active') {
                $object['active'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($key === 'description') {
                $object['description'] = $value === '' ? null : $value;
            } else {
                $object[$key] = $value;
            }
        }

        return $object;
    }

    private function matchesSearch(array $product, string $query): bool
    {
        return preg_match("/^metadata\\['([^']+)'\\]:'([^']*)'$/", $query, $m) === 1
            && (string) ($product['metadata'][$m[1]] ?? '') === $m[2];
    }

    private function list(array $data, string $url, string $object = 'list'): array
    {
        return ['object' => $object, 'data' => $data, 'has_more' => false, 'url' => $url];
    }

    private function ok(array $body): array
    {
        if (isset($body['error'])) {
            return [json_encode($body), 400, []];
        }

        return [json_encode($body), 200, []];
    }

    private function error(string $message): array
    {
        return ['error' => ['type' => 'invalid_request_error', 'message' => $message]];
    }

    private function missing(string $object, string $id): array
    {
        return [json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such {$object}: '{$id}'"]]), 404, []];
    }
}
