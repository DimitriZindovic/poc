<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    protected $shopifyUrl;
    protected $apiKey;
    protected $apiPassword;
    protected $apiVersion;
    protected $storeDomain;
    protected $adminAccessToken;

    public function __construct()
    {
        $this->shopifyUrl = (string) config('services.shopify.api_url');
        $this->apiKey = (string) config('services.shopify.api_key');
        $this->apiPassword = (string) config('services.shopify.api_password');
        $this->apiVersion = (string) config('services.shopify.api_version', '2025-01');
        $this->storeDomain = (string) config('services.shopify.store_domain', '');
        $this->adminAccessToken = (string) config('services.shopify.admin_access_token', '');

        if ($this->storeDomain === '' && $this->shopifyUrl !== '') {
            $host = parse_url($this->shopifyUrl, PHP_URL_HOST);
            $this->storeDomain = is_string($host) ? $host : '';
        }
    }

    public function createProduct($name, $price)
    {
        $url = "{$this->shopifyUrl}/admin/api/{$this->apiVersion}/products.json";

        $response = Http::withBasicAuth($this->apiKey, $this->apiPassword)
            ->post($url, [
                'product' => [
                    'title' => $name,
                    'variants' => [
                        ['price' => $price],
                    ],
                ],
            ]);

        if (!$response->successful()) {
            Log::error('Failed to create product in Shopify', [
                'url' => $url,
                'payload' => [
                    'product' => [
                        'title' => $name,
                        'variants' => [
                            ['price' => $price],
                        ],
                    ],
                ],
                'response' => $response->body(),
            ]);
            throw new \Exception('Failed to create product in Shopify: ' . $response->body());
        }

        $responseData = $response->json();
        if (!isset($responseData['product'])) {
            throw new \Exception('Unexpected Shopify API response: ' . json_encode($responseData));
        }

        Log::info('Product created in Shopify', [
            'product_id' => $responseData['product']['id'],
            'title' => $responseData['product']['title'],
        ]);

        return $responseData;
    }

    public function listProducts(): array
    {
        $url = "{$this->shopifyUrl}/admin/api/{$this->apiVersion}/products.json?limit=50";

        $response = Http::withBasicAuth($this->apiKey, $this->apiPassword)->get($url);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch Shopify products: ' . $response->body());
        }

        return $response->json('products', []);
    }

    public function listOrders(): array
    {
        $query = <<<'GQL'
                        query GetOrders($first: Int!, $after: String) {
                            orders(first: $first, after: $after, sortKey: CREATED_AT, reverse: true, query: "financial_status:paid") {
                                pageInfo {
                                    hasNextPage
                                    endCursor
                                }
                                edges {
                                    node {
                                        id
                                        name
                                        createdAt
                                        displayFinancialStatus
                                        totalPriceSet {
                                            shopMoney {
                                                amount
                                                currencyCode
                                            }
                                        }
                                        email
                                        shippingAddress {
                                            address1
                                            city
                                            zip
                                            country
                                        }
                                        lineItems(first: 10) {
                                            edges {
                                                node {
                                                    title
                                                    quantity
                                                    sellingPlan {
                                                        name
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                GQL;

        $orders = [];
        $cursor = null;

        do {
            $payload = $this->graphqlQuery($query, [
                'first' => 50,
                'after' => $cursor,
            ]);

            $connection = Arr::get($payload, 'data.orders', []);
            foreach (Arr::get($connection, 'edges', []) as $edge) {
                $orders[] = $this->normalizeGraphqlOrder((array) ($edge['node'] ?? []));
            }

            $pageInfo = Arr::get($connection, 'pageInfo', []);
            $hasNextPage = (bool) Arr::get($pageInfo, 'hasNextPage', false);
            $cursor = Arr::get($pageInfo, 'endCursor');
        } while ($hasNextPage && !empty($cursor));

        return $orders;
    }

    public function createTestOrder(int|string $variantId, string $email): array
    {
        $url = "{$this->shopifyUrl}/admin/api/{$this->apiVersion}/orders.json";

        $payload = [
            'order' => [
                'email' => $email,
                'line_items' => [
                    [
                        'variant_id' => (int) $variantId,
                        'quantity' => 1,
                    ],
                ],
                'financial_status' => 'paid',
                'test' => true,
                'send_receipt' => false,
                'send_fulfillment_receipt' => false,
            ],
        ];

        $response = Http::withBasicAuth($this->apiKey, $this->apiPassword)->post($url, $payload);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to create test order in Shopify: ' . $response->body());
        }

        return $response->json('order', []);
    }

    public function buildStorefrontCheckoutUrl(int $variantId, string $email): string
    {
        $base = rtrim($this->shopifyUrl, '/');
        $query = http_build_query([
            'checkout[email]' => $email,
        ]);

        return $base . '/cart/' . $variantId . ':1?' . $query;
    }

    public function getAccessScopes(): array
    {
        $url = "{$this->shopifyUrl}/admin/oauth/access_scopes.json";

        $response = Http::withBasicAuth($this->apiKey, $this->apiPassword)->get($url);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch Shopify access scopes: ' . $response->body());
        }

        return $response->json('access_scopes', []);
    }

    /**
     * Query single order with selling plan details.
     */
    public function getOrderWithSellingPlan(string $orderGid): array
    {
        $query = <<<'GQL'
            query GetOrderWithSellingPlan($orderId: ID!) {
              order(id: $orderId) {
                id
                name
                createdAt
                                email
                lineItems(first: 10) {
                  edges {
                    node {
                      title
                      quantity
                                            sellingPlan {
                                                name
                      }
                    }
                  }
                }
              }
            }
        GQL;

        $payload = $this->graphqlQuery($query, ['orderId' => $orderGid]);
        $order = (array) Arr::get($payload, 'data.order', []);

        return $this->normalizeGraphqlOrder($order);
    }

    /**
     * Fetch subscription contracts with pagination and related renewal orders.
     */
    public function listSubscriptionContracts(): array
    {
        $query = <<<'GQL'
            query GetSubscriptionContracts($first: Int!, $after: String) {
              subscriptionContracts(first: $first, after: $after) {
                pageInfo {
                  hasNextPage
                  endCursor
                }
                edges {
                  node {
                    id
                    status
                    nextBillingDate
                    billingPolicy {
                      interval
                      intervalCount
                    }
                    customer {
                      id
                      email
                    }
                    originOrder {
                      id
                      name
                    }
                    orders(first: 20) {
                      edges {
                        node {
                          id
                          name
                          createdAt
                          totalPriceSet {
                            shopMoney {
                              amount
                              currencyCode
                            }
                          }
                        }
                      }
                    }
                    lines(first: 10) {
                      edges {
                        node {
                          title
                          quantity
                        }
                      }
                    }
                  }
                }
              }
            }
        GQL;

        $contracts = [];
        $cursor = null;

        do {
            $payload = $this->graphqlQuery($query, [
                'first' => 50,
                'after' => $cursor,
            ]);

            $connection = Arr::get($payload, 'data.subscriptionContracts', []);
            foreach (Arr::get($connection, 'edges', []) as $edge) {
                $contracts[] = $this->normalizeSubscriptionContract((array) ($edge['node'] ?? []));
            }

            $pageInfo = Arr::get($connection, 'pageInfo', []);
            $hasNextPage = (bool) Arr::get($pageInfo, 'hasNextPage', false);
            $cursor = Arr::get($pageInfo, 'endCursor');
        } while ($hasNextPage && !empty($cursor));

        return $contracts;
    }

    private function normalizeGraphqlOrder(array $order): array
    {
        $lineItems = collect(Arr::get($order, 'lineItems.edges', []))
            ->map(fn(array $edge) => (array) ($edge['node'] ?? []))
            ->values();

        $firstLineItem = (array) ($lineItems->first() ?? []);
        $sellingPlanName = Arr::get($firstLineItem, 'sellingPlan.name');

        return [
            'shopify_order_id' => (string) Arr::get($order, 'id', ''),
            'order_name' => Arr::get($order, 'name'),
            'email' => Arr::get($order, 'email'),
            'customer_name' => '',
            'financial_status' => Arr::get($order, 'displayFinancialStatus', 'PAID'),
            'total_price' => Arr::get($order, 'totalPriceSet.shopMoney.amount'),
            'currency' => Arr::get($order, 'totalPriceSet.shopMoney.currencyCode', 'EUR'),
            'line_item_title' => Arr::get($firstLineItem, 'title'),
            'line_item_quantity' => Arr::get($firstLineItem, 'quantity', 1),
            'selling_plan_name' => $sellingPlanName,
            'shipping_address' => Arr::get($order, 'shippingAddress'),
            'created_at' => Arr::get($order, 'createdAt'),
            'order_status_url' => null,
        ];
    }

    private function normalizeSubscriptionContract(array $contract): array
    {
        $lines = collect(Arr::get($contract, 'lines.edges', []))
            ->map(fn(array $edge) => (array) ($edge['node'] ?? []))
            ->values();
        $firstLine = (array) ($lines->first() ?? []);

        $renewalOrders = collect(Arr::get($contract, 'orders.edges', []))
            ->map(function (array $edge) {
                $node = (array) ($edge['node'] ?? []);

                return [
                    'orderId' => Arr::get($node, 'id'),
                    'orderName' => Arr::get($node, 'name'),
                    'createdAt' => Arr::get($node, 'createdAt'),
                    'amount' => Arr::get($node, 'totalPriceSet.shopMoney.amount'),
                    'currency' => Arr::get($node, 'totalPriceSet.shopMoney.currencyCode'),
                ];
            })
            ->values()
            ->all();

        return [
            'contractId' => Arr::get($contract, 'id'),
            'status' => Arr::get($contract, 'status'),
            'customerEmail' => Arr::get($contract, 'customer.email'),
            'planName' => Arr::get($firstLine, 'title'),
            'billingInterval' => Arr::get($contract, 'billingPolicy.interval'),
            'billingIntervalCount' => Arr::get($contract, 'billingPolicy.intervalCount'),
            'nextBillingDate' => Arr::get($contract, 'nextBillingDate'),
            'originOrderId' => Arr::get($contract, 'originOrder.id'),
            'originOrderName' => Arr::get($contract, 'originOrder.name'),
            'renewalOrders' => $renewalOrders,
        ];
    }

    private function graphqlQuery(string $query, array $variables = []): array
    {
        if ($this->storeDomain === '' && $this->shopifyUrl === '') {
            throw new \RuntimeException('Shopify store domain missing. Configure SHOPIFY_STORE_DOMAIN or SHOPIFY_API_URL.');
        }

        $base = $this->storeDomain !== ''
            ? 'https://' . $this->storeDomain
            : rtrim($this->shopifyUrl, '/');

        $url = $base . '/admin/api/' . $this->apiVersion . '/graphql.json';

        $client = Http::acceptJson();
        if ($this->adminAccessToken !== '') {
            $client = $client->withHeaders([
                'X-Shopify-Access-Token' => $this->adminAccessToken,
            ]);
        } else {
            $client = $client->withBasicAuth($this->apiKey, $this->apiPassword);
        }

        $response = $client->post($url, [
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($response->status() === 429) {
            throw new \RuntimeException('Shopify rate limit reached (429). Retry in a few seconds.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new \RuntimeException('Shopify token invalid or missing scopes (HTTP ' . $response->status() . ').');
        }

        if (!$response->successful()) {
            throw new \RuntimeException('Shopify GraphQL HTTP error: ' . $response->status() . ' - ' . $response->body());
        }

        $payload = (array) $response->json();
        $errors = Arr::get($payload, 'errors', []);
        if (!empty($errors)) {
            $message = collect($errors)
                ->map(fn(array $err) => (string) ($err['message'] ?? 'Unknown GraphQL error'))
                ->implode(' | ');

            throw new \RuntimeException('Shopify GraphQL subscriptions error: ' . $message);
        }

        return $payload;
    }
}
