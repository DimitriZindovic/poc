<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    protected $shopifyUrl;
    protected $apiKey;
    protected $apiPassword;
    protected $apiVersion;

    public function __construct()
    {
        $this->shopifyUrl = (string) config('services.shopify.api_url');
        $this->apiKey = (string) config('services.shopify.api_key');
        $this->apiPassword = (string) config('services.shopify.api_password');
        $this->apiVersion = (string) config('services.shopify.api_version', '2025-04');
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
        $url = "{$this->shopifyUrl}/admin/api/{$this->apiVersion}/orders.json?limit=50&status=any&financial_status=paid&order=created_at%20desc";

        $response = Http::withBasicAuth($this->apiKey, $this->apiPassword)->get($url);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch Shopify orders: ' . $response->body());
        }

        return $response->json('orders', []);
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
}
