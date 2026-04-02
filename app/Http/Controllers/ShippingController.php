<?php

namespace App\Http\Controllers;

use App\Models\ShopifyOrder;
use App\Models\ShippingList;
use App\Models\ShippingListItem;
use App\Models\Subscription;
use App\Services\ShopifyService;
use App\Services\SubscriptionWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ShippingController extends Controller
{
    public function __construct(
        private readonly ShopifyService $shopifyService,
        private readonly SubscriptionWorkflowService $workflowService,
    ) {
    }

    /**
     * Générer des données test
     */
    public function generateTestData()
    {
        $emails = [
            'alice@example.com',
            'bob.smith@example.com',
            'charlie.brown@example.com',
            'diana.prince@example.com',
            'eve.johnson@example.com',
            'martin.dupont@example.com',
            'sarah.bernard@example.com',
            'leo.moreau@example.com',
            'nina.laurent@example.com',
            'hugo.martin@example.com',
        ];

        $customerNames = [
            'Alice Martin',
            'Bob Smith',
            'Charlie Brown',
            'Diana Prince',
            'Eve Johnson',
            'Martin Dupont',
            'Sarah Bernard',
            'Leo Moreau',
            'Nina Laurent',
            'Hugo Martin',
        ];

        $productTitles = $this->resolveTestProductTitles();
        $statusPattern = ['active', 'active', 'paused', 'completed', 'active'];
        $count = 5;

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($emails, $customerNames, $productTitles, $statusPattern, $count) {
                for ($i = 1; $i <= $count; $i++) {
                    $email = $emails[($i - 1) % count($emails)];
                    $customerName = $customerNames[($i - 1) % count($customerNames)];
                    $productTitle = $productTitles[($i - 1) % count($productTitles)];
                    $shippingMethod = $this->detectShippingMethod($productTitle);
                    $totalBoxes = $this->detectTotalBoxes($productTitle);
                    $status = $statusPattern[($i - 1) % count($statusPattern)];

                    if ($status === 'completed') {
                        $shippedBoxes = $totalBoxes;
                        $nextShipment = now()->addDays(rand(15, 45))->toDateString();
                    } elseif ($status === 'paused') {
                        $shippedBoxes = rand(1, max(1, min(3, $totalBoxes - 1)));
                        $nextShipment = now()->addDays(rand(7, 21))->toDateString();
                    } else {
                        $shippedBoxes = rand(0, max(1, min(4, $totalBoxes - 1)));
                        $nextShipment = now()->subDays(rand(1, 6))->toDateString();
                    }

                    $order = ShopifyOrder::create([
                        'shopify_order_id' => 'TEST-' . now()->format('YmdHis') . '-' . $i,
                        'email' => $email,
                        'customer_name' => $customerName,
                        'shipping_address' => [
                            'address1' => rand(10, 999) . ' Rue de Test',
                            'city' => ['Paris', 'Lyon', 'Marseille', 'Toulouse'][rand(0, 3)],
                            'zip' => '7500' . rand(0, 9),
                            'country' => 'France',
                            'phone' => '+33612345' . str_pad($i, 3, '0', STR_PAD_LEFT),
                        ],
                        'financial_status' => 'paid',
                        'raw_payload' => [],
                    ]);

                    Subscription::create([
                        'shopify_order_ref_id' => $order->id,
                        'shopify_customer_id' => 'CUST123' . $i,
                        'email' => $email,
                        'product_title' => $productTitle,
                        'total_boxes' => $totalBoxes,
                        'shipped_boxes' => $shippedBoxes,
                        'status' => $status,
                        'shipping_method' => $shippingMethod,
                        'shipping_address' => $order->shipping_address,
                        'next_shipment_at' => $nextShipment,
                        'last_checked_at' => now(),
                    ]);
                }
            });

            return response()->json([
                'message' => '✓ 5 commandes test générées',
                'count' => $count,
                'products_used' => $productTitles,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Erreur génération',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveTestProductTitles(): array
    {
        try {
            $products = $this->shopifyService->listProducts();

            $subscriptionProducts = collect($products)
                ->map(fn(array $product) => (string) ($product['title'] ?? ''))
                ->filter(
                    fn(string $title) =>
                    str_contains(mb_strtolower($title), 'abonnement')
                    || str_contains(mb_strtolower($title), 'box')
                )
                ->take(2)
                ->values()
                ->all();

            if (count($subscriptionProducts) >= 2) {
                return $subscriptionProducts;
            }

            $fallbackFromShopify = collect($products)
                ->map(fn(array $product) => (string) ($product['title'] ?? ''))
                ->filter(fn(string $title) => $title !== '')
                ->take(2)
                ->values()
                ->all();

            if (count($fallbackFromShopify) >= 2) {
                return $fallbackFromShopify;
            }
        } catch (\Throwable $e) {
            Log::warning('Impossible de récupérer les produits Shopify pour les données test', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'Abonnement Standard 6 boxes',
            'Abonnement Express 6 boxes',
        ];
    }

    private function detectShippingMethod(string $productTitle): string
    {
        $title = mb_strtolower($productTitle);

        if (str_contains($title, 'express')) {
            return 'express';
        }

        if (str_contains($title, 'priority') || str_contains($title, 'prioritaire')) {
            return 'priority';
        }

        if (str_contains($title, 'international')) {
            return 'international';
        }

        return 'standard';
    }

    private function detectTotalBoxes(string $productTitle): int
    {
        $title = mb_strtolower($productTitle);

        if (str_contains($title, '1 ans') || str_contains($title, '1 an') || str_contains($title, '12 mois')) {
            return 12;
        }

        if (str_contains($title, '3 mois')) {
            return 3;
        }

        return 6;
    }

    private function buildTrackingFallback(string $shippingMethod, int $shippingListId, int $subscriptionId, int $boxNumber): string
    {
        $dateCode = now()->format('ymd');
        $listCode = str_pad((string) $shippingListId, 3, '0', STR_PAD_LEFT);
        $subCode = str_pad((string) $subscriptionId, 4, '0', STR_PAD_LEFT);
        $boxCode = str_pad((string) $boxNumber, 2, '0', STR_PAD_LEFT);

        return match ($shippingMethod) {
            'express' => '1Z' . $listCode . $subCode . $boxCode . 'FR',
            'priority' => 'CH' . $dateCode . $listCode . $subCode,
            'international' => 'LX' . $dateCode . $listCode . $subCode . 'FR',
            default => '6A' . $dateCode . $listCode . $subCode . $boxCode,
        };
    }
    /**
     * Lister toutes les subscriptions avec leurs statuts
     */
    public function subscriptions()
    {
        $subscriptions = Subscription::with('order')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($sub) {
                $order = $sub->order;
                $rawPayload = is_array($order?->raw_payload) ? $order->raw_payload : [];
                $lineItems = Arr::get($rawPayload, 'line_items', []);
                $firstLineItem = is_array($lineItems) && !empty($lineItems) ? $lineItems[0] : [];

                return [
                    'id' => $sub->id,
                    'email' => $sub->email,
                    'product_title' => $sub->product_title,
                    'status' => $sub->status,
                    'shipping_method' => $sub->shipping_method,
                    'total_boxes' => $sub->total_boxes,
                    'shipped_boxes' => $sub->shipped_boxes,
                    'boxes_remaining' => $sub->total_boxes - $sub->shipped_boxes,
                    'progress_percent' => (int) (($sub->shipped_boxes / $sub->total_boxes) * 100),
                    'next_shipment_at' => (string) $sub->next_shipment_at,
                    'is_due' => optional($sub->next_shipment_at)->lte(now()),
                    'created_at' => $sub->created_at,
                    'shipping_address' => $sub->shipping_address,
                    'order' => [
                        'id' => $order?->id,
                        'shopify_order_id' => $order?->shopify_order_id,
                        'customer_name' => $order?->customer_name,
                        'financial_status' => $order?->financial_status,
                        'total_price' => Arr::get($rawPayload, 'total_price', Arr::get($firstLineItem, 'price')),
                        'currency' => Arr::get($rawPayload, 'currency', Arr::get($rawPayload, 'presentment_currency')),
                        'line_item_title' => Arr::get($firstLineItem, 'title'),
                        'line_item_quantity' => Arr::get($firstLineItem, 'quantity'),
                        'created_at' => $order?->created_at?->toIso8601String(),
                        'shipping_address' => $order?->shipping_address ?? $sub->shipping_address,
                    ],
                ];
            });

        return response()->json([
            'count' => $subscriptions->count(),
            'subscriptions' => $subscriptions,
        ]);
    }

    /**
     * Récupérer la dernière shipping list
     */
    public function latest()
    {
        $latest = ShippingList::query()
            ->latest('id')
            ->first();

        $itemsQuery = ShippingListItem::query()->with('subscription');

        if ($latest) {
            $itemsQuery->where('shipping_list_id', $latest->id);
        }

        $items = $itemsQuery
            ->latest('id')
            ->limit(100)
            ->get();

        if (!$latest && $items->isEmpty()) {
            return response()->json(['shipping_list' => null]);
        }

        return response()->json([
            'shipping_list' => [
                'id' => $latest?->id,
                'run_date' => $latest ? (string) $latest->run_date : null,
                'items_count' => $items->count(),
                'items' => $items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'shipping_list_id' => $item->shipping_list_id,
                        'subscription_id' => $item->subscription_id,
                        'box_number' => $item->box_number,
                        'status' => $item->status,
                        'shipped_status' => $item->shipped_status,
                        'shipping_method' => $item->shipping_method,
                        'tracking_number' => $item->tracking_number ?: $this->buildTrackingFallback(
                            (string) ($item->shipping_method ?? 'standard'),
                            (int) $item->shipping_list_id,
                            (int) $item->subscription_id,
                            (int) $item->box_number
                        ),
                        'email' => $item->subscription?->email,
                        'product_title' => $item->subscription?->product_title,
                        'subscription_status' => $item->subscription?->status,
                        'shipped_boxes' => $item->subscription?->shipped_boxes,
                        'total_boxes' => $item->subscription?->total_boxes,
                        'address' => $item->shipping_snapshot,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Génère immédiatement une liste de commandes à expédier
     */
    public function generateShippingNow()
    {
        try {
            $result = $this->workflowService->generateShippingList(now(), false);

            return response()->json([
                'message' => 'Liste de commandes générée',
                'eligible_subscriptions' => $result['eligible_subscriptions'],
                'generated_items' => $result['generated_items'],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Failed to generate shipping list from API', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Impossible de générer la liste des commandes',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Marquer un item d'expédition comme expédié et ajouter tracking
     */
    public function markShipped(Request $request, ShippingListItem $item)
    {
        $validated = $request->validate([
            'tracking_number' => ['required', 'string'],
            'shipped_status' => ['required', 'in:packed,shipped,in_transit,delivered'],
        ]);

        try {
            $item->update([
                'tracking_number' => $validated['tracking_number'],
                'shipped_status' => $validated['shipped_status'],
                'status' => 'shipped',
            ]);

            return response()->json([
                'message' => 'Item marque comme expedie',
                'item' => [
                    'id' => $item->id,
                    'tracking_number' => $item->tracking_number,
                    'shipped_status' => $item->shipped_status,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to mark shipping item as shipped', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Echec de mise a jour du statut',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Afficher les détails d'un item
     */
    public function detail(ShippingListItem $item)
    {
        return response()->json([
            'item' => [
                'id' => $item->id,
                'subscription_id' => $item->subscription_id,
                'box_number' => $item->box_number,
                'status' => $item->status,
                'shipped_status' => $item->shipped_status,
                'shipping_method' => $item->shipping_method,
                'tracking_number' => $item->tracking_number,
                'shipping_address' => $item->shipping_snapshot,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
                'subscription' => $item->subscription ? [
                    'email' => $item->subscription->email,
                    'product_title' => $item->subscription->product_title,
                    'total_boxes' => $item->subscription->total_boxes,
                    'shipped_boxes' => $item->subscription->shipped_boxes,
                ] : null,
            ],
        ]);
    }
}
