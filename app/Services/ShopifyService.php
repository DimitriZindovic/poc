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
        $this->shopifyUrl = env('SHOPIFY_API_URL');
        $this->apiKey = env('SHOPIFY_API_KEY');
        $this->apiPassword = env('SHOPIFY_API_PASSWORD');
        $this->apiVersion = env('SHOPIFY_API_VERSION', '2025-04');
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
}
