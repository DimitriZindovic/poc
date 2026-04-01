<?php

namespace App\Http\Controllers;

use App\Models\ShopifyOrder;
use App\Models\Subscription;
use App\Services\ShopifyService;
use App\Services\SubscriptionWorkflowService;
use Illuminate\Http\Request;
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
