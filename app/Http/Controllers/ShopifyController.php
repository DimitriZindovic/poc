<?php

namespace App\Http\Controllers;

use App\Models\ShopifyOrder;
use App\Models\Subscription;
use App\Services\ShopifyService;
use App\Services\SubscriptionWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ShopifyController extends Controller
{
    public function __construct(
        private readonly ShopifyService $shopifyService,
        private readonly SubscriptionWorkflowService $workflowService,
    ) {
    }

    public function products()
    {
        try {
            $products = $this->shopifyService->listProducts();

            return response()->json(['products' => $products]);
        } catch (\Throwable $e) {
            Log::error('Failed to list Shopify products', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Impossible de recuperer les produits Shopify'], 500);
        }
    }

    public function accessScopes()
    {
        try {
            $scopes = $this->shopifyService->getAccessScopes();
            $scopeHandles = array_map(fn(array $scope) => (string) ($scope['handle'] ?? ''), $scopes);

            return response()->json([
                'scopes' => $scopeHandles,
                'has_write_orders' => in_array('write_orders', $scopeHandles, true),
                'has_read_orders' => in_array('read_orders', $scopeHandles, true),
                'has_read_products' => in_array('read_products', $scopeHandles, true),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to read Shopify access scopes', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Impossible de verifier les scopes Shopify',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function orders()
    {
        try {
            $shopifyOrders = $this->shopifyService->listOrders();
            $subscriptionGroups = Subscription::with('order')
                ->get()
                ->groupBy(fn(Subscription $subscription) => (string) $subscription->shopify_order_ref_id);

            $orders = collect($shopifyOrders)
                ->map(function (array $order) use ($subscriptionGroups) {
                    $lineItems = Arr::get($order, 'line_items', []);
                    $firstLineItem = is_array($lineItems) && !empty($lineItems) ? $lineItems[0] : [];
                    $shippingAddress = Arr::get($order, 'shipping_address');
                    $orderId = (string) Arr::get($order, 'id', '');
                    $localSubscriptions = $subscriptionGroups->get($orderId, collect());

                    return [
                        'shopify_order_id' => $orderId,
                        'email' => Arr::get($order, 'email'),
                        'customer_name' => trim((string) (Arr::get($order, 'customer.first_name', '') . ' ' . Arr::get($order, 'customer.last_name', ''))),
                        'financial_status' => Arr::get($order, 'financial_status', 'paid'),
                        'total_price' => Arr::get($order, 'total_price', Arr::get($firstLineItem, 'price')),
                        'currency' => Arr::get($order, 'currency', Arr::get($order, 'presentment_currency', 'EUR')),
                        'line_item_title' => Arr::get($firstLineItem, 'title'),
                        'line_item_quantity' => Arr::get($firstLineItem, 'quantity', 1),
                        'shipping_address' => $shippingAddress,
                        'created_at' => Arr::get($order, 'created_at'),
                        'subscription_count' => $localSubscriptions->count(),
                        'shipping_method' => optional($localSubscriptions->first())->shipping_method,
                        'local_status' => optional($localSubscriptions->first())->status,
                        'shipped_boxes' => (int) $localSubscriptions->sum('shipped_boxes'),
                        'total_boxes' => (int) $localSubscriptions->sum('total_boxes'),
                        'next_shipment_at' => optional($localSubscriptions->first())->next_shipment_at?->toIso8601String(),
                        'order_status_url' => Arr::get($order, 'order_status_url'),
                    ];
                })
                ->sortByDesc(fn(array $order) => $order['created_at'] ?? '')
                ->values();

            return response()->json([
                'count' => $orders->count(),
                'orders' => $orders,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to list Shopify orders', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Impossible de recuperer les commandes Shopify',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function simulateOrder(Request $request)
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'numeric'],
            'email' => ['required', 'email'],
        ]);

        $requiredScopes = ['read_products', 'read_orders', 'write_orders'];
        $missingScopes = $this->missingRequiredScopes($requiredScopes);

        if (!empty($missingScopes)) {
            return response()->json([
                'error' => 'Scopes Shopify manquants',
                'missing_scopes' => $missingScopes,
                'required_scopes' => $requiredScopes,
                'hint' => 'Ajoute les scopes manquants, reinstalle l\'app Shopify puis mets a jour SHOPIFY_API_PASSWORD.',
            ], 403);
        }

        try {
            $order = $this->shopifyService->createTestOrder((int) $validated['variant_id'], $validated['email']);
            $ingest = $this->workflowService->ingestPaidOrder($order);

            return response()->json([
                'message' => 'Commande test creee dans Shopify et abonnement enregistre',
                'mode' => 'shopify_admin_api',
                'shopify_order_id' => $order['id'] ?? null,
                'created_subscriptions' => $ingest['created_subscriptions'],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Failed to simulate Shopify order', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Echec de simulation commande',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function missingRequiredScopes(array $requiredScopes): array
    {
        $scopes = $this->shopifyService->getAccessScopes();
        $scopeHandles = array_map(fn(array $scope) => (string) ($scope['handle'] ?? ''), $scopes);

        return array_values(array_filter(
            $requiredScopes,
            fn(string $scope) => !in_array($scope, $scopeHandles, true)
        ));
    }

    public function checkoutStatus(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = $validated['email'];

        $latestOrder = ShopifyOrder::query()
            ->where('email', $email)
            ->latest('id')
            ->first();

        if (!$latestOrder) {
            return response()->json([
                'found' => false,
                'message' => 'Aucune commande Shopify ingeree pour cet email pour le moment.',
            ]);
        }

        $subscriptions = Subscription::query()
            ->where('shopify_order_ref_id', $latestOrder->id)
            ->get();

        return response()->json([
            'found' => true,
            'order' => [
                'id' => $latestOrder->id,
                'shopify_order_id' => $latestOrder->shopify_order_id,
                'email' => $latestOrder->email,
                'financial_status' => $latestOrder->financial_status,
                'created_at' => $latestOrder->created_at?->toIso8601String(),
            ],
            'subscriptions_count' => $subscriptions->count(),
            'subscriptions' => $subscriptions->map(fn(Subscription $subscription) => [
                'id' => $subscription->id,
                'product_title' => $subscription->product_title,
                'status' => $subscription->status,
                'shipped_boxes' => $subscription->shipped_boxes,
                'total_boxes' => $subscription->total_boxes,
            ]),
        ]);
    }

    public function ordersPaidWebhook(Request $request)
    {
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
        $secret = (string) config('services.shopify.webhook_secret');

        if (!$hmacHeader || $secret === '') {
            return response()->json(['error' => 'Missing webhook signature'], 400);
        }

        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (!hash_equals($hmacHeader, $computed)) {
            return response()->json(['error' => 'Invalid webhook signature'], 401);
        }

        try {
            $payload = $request->json()->all();
            $result = $this->workflowService->ingestPaidOrder($payload);

            return response()->json([
                'message' => 'Webhook traite',
                'created_subscriptions' => $result['created_subscriptions'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to process Shopify webhook', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Webhook processing error'], 500);
        }
    }
}
