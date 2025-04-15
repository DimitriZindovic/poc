<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductController extends Controller
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

    public function index()
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload()->toArray();
            $userId = $payload['sub'] ?? null;

            if (!$userId) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            $supabaseUrl = env('SUPABASE_URL') . '/rest/v1/products';
            $supabaseKey = env('SUPABASE_KEY');

            $response = Http::withHeaders([
                'apikey' => $supabaseKey,
                'Authorization' => 'Bearer ' . $supabaseKey,
            ])->get($supabaseUrl, [
                        'created_by' => 'eq.' . $userId,
                    ]);

            if (!$response->successful()) {
                return response()->json(['error' => 'Failed to fetch products'], 500);
            }

            return response()->json($response->json());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
        ]);

        try {
            $payload = JWTAuth::parseToken()->getPayload()->toArray();
            $userId = $payload['sub'] ?? null;

            if (!$userId) {
                Log::error('User ID not found in token payload');
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            $url = "{$this->shopifyUrl}/admin/api/{$this->apiVersion}/products.json";

            $response = Http::withBasicAuth($this->apiKey, $this->apiPassword)
                ->post($url, [
                    'product' => [
                        'title' => $request->name,
                        'variants' => [
                            ['price' => $request->price],
                        ],
                    ],
                ]);

            Log::info('Shopify API Response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::error('Failed to create product in Shopify', [
                    'url' => $url,
                    'payload' => [
                        'product' => [
                            'title' => $request->name,
                            'variants' => [
                                ['price' => $request->price],
                            ],
                        ],
                    ],
                    'response' => $response->body(),
                ]);
                return response()->json(['error' => 'Failed to create product in Shopify'], 500);
            }

            $shopifyProduct = $response->json();

            if (!isset($shopifyProduct['product']) || !isset($shopifyProduct['product']['id'])) {
                Log::error('Unexpected Shopify API response', ['response' => $shopifyProduct]);
                throw new \Exception('Unexpected Shopify API response: ' . json_encode($shopifyProduct));
            }

            Log::info('Product created in Shopify', [
                'product_id' => $shopifyProduct['product']['id'],
                'title' => $shopifyProduct['product']['title'],
            ]);

            $supabaseUrl = env('SUPABASE_URL') . '/rest/v1/products';
            $supabaseKey = env('SUPABASE_KEY');

            $response = Http::withHeaders([
                'apikey' => $supabaseKey,
                'Authorization' => 'Bearer ' . $supabaseKey,
            ])->post($supabaseUrl, [
                        'shopify_id' => $shopifyProduct['product']['id'],
                        'created_by' => $userId,
                        'sales_count' => 0,
                    ]);

            if (!$response->successful()) {
                Log::error('Failed to save product in Supabase', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return response()->json(['error' => 'Failed to save product in Supabase'], 500);
            }

            return response()->json(['message' => 'Product created successfully'], 201);
        } catch (\Exception $e) {
            Log::error('Error in ProductController@store', ['exception' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function myProducts()
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload()->toArray();
            $userId = $payload['sub'] ?? null;

            if (!$userId) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            $supabaseUrl = env('SUPABASE_URL') . '/rest/v1/products';
            $supabaseKey = env('SUPABASE_KEY');

            $response = Http::withHeaders([
                'apikey' => $supabaseKey,
                'Authorization' => 'Bearer ' . $supabaseKey,
            ])->get($supabaseUrl, [
                        'created_by' => 'eq.' . $userId,
                    ]);

            if (!$response->successful()) {
                return response()->json(['error' => 'Failed to fetch products'], 500);
            }

            return response()->json($response->json());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function handleShopifySalesWebhook(Request $request)
    {
        try {
            $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');

            if (!$hmacHeader) {
                Log::error('Missing HMAC header for Shopify webhook');
                return response()->json(['error' => 'Missing HMAC header'], 400);
            }

            $calculatedHmac = base64_encode(hash_hmac('sha256', $request->getContent(), env('SHOPIFY_WEBHOOK'), true));

            if (!hash_equals($hmacHeader, $calculatedHmac)) {
                Log::error('Invalid HMAC signature for Shopify webhook');
                return response()->json(['error' => 'Invalid HMAC signature'], 401);
            }

            $orderData = $request->json()->all();

            foreach ($orderData['line_items'] as $item) {
                $shopifyProductId = $item['product_id'];
                $quantity = $item['quantity'];

                $supabaseUrl = env('SUPABASE_URL') . '/rest/v1/products';
                $supabaseKey = env('SUPABASE_KEY');

                $checkResponse = Http::withHeaders([
                    'apikey' => $supabaseKey,
                    'Authorization' => 'Bearer ' . $supabaseKey,
                ])->get("{$supabaseUrl}?shopify_id=eq.{$shopifyProductId}");

                if (!$checkResponse->successful() || empty($checkResponse->json())) {
                    Log::error('Product not found in Supabase', [
                        'shopify_id' => $shopifyProductId,
                    ]);
                    continue;
                }

                $product = $checkResponse->json()[0];
                $currentSalesCount = $product['sales_count'] ?? 0;

                $newSalesCount = $currentSalesCount + $quantity;

                $updateResponse = Http::withHeaders([
                    'apikey' => $supabaseKey,
                    'Authorization' => 'Bearer ' . $supabaseKey,
                ])->patch("{$supabaseUrl}?shopify_id=eq.{$shopifyProductId}", [
                            'sales_count' => $newSalesCount,
                        ]);

                if (!$updateResponse->successful()) {
                    Log::error('Failed to update sales_count in Supabase', [
                        'product_id' => $shopifyProductId,
                        'quantity' => $quantity,
                        'response' => $updateResponse->body(),
                    ]);
                }
            }

            return response()->json(['message' => 'Webhook processed successfully'], 200);
        } catch (\Exception $e) {
            Log::error('Error processing Shopify webhook', ['exception' => $e->getMessage()]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}
