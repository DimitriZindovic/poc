<?php

namespace App\Services;

use App\Models\ShippingList;
use App\Models\ShippingListItem;
use App\Models\ShopifyOrder;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SubscriptionWorkflowService
{
    public function ingestPaidOrder(array $orderPayload, ?string $subscriptionType = null): array
    {
        return DB::transaction(function () use ($orderPayload, $subscriptionType) {
            $shopifyOrder = ShopifyOrder::updateOrCreate(
                ['shopify_order_id' => (string) $orderPayload['id']],
                [
                    'email' => Arr::get($orderPayload, 'email'),
                    'customer_name' => trim((string) (Arr::get($orderPayload, 'customer.first_name', '') . ' ' . Arr::get($orderPayload, 'customer.last_name', ''))),
                    'shipping_address' => Arr::get($orderPayload, 'shipping_address'),
                    'financial_status' => Arr::get($orderPayload, 'financial_status'),
                    'raw_payload' => $orderPayload,
                ]
            );

            $createdSubscriptions = 0;

            // Mode manuel: créer une subscription avec le type spécifié
            if (!empty($subscriptionType)) {
                $shippingMethod = $this->detectShippingMethod($subscriptionType);
                $totalBoxes = $this->detectTotalBoxes($subscriptionType);

                Subscription::create([
                    'shopify_order_ref_id' => $shopifyOrder->id,
                    'shopify_customer_id' => (string) Arr::get($orderPayload, 'customer.id', 'MANUAL'),
                    'email' => Arr::get($orderPayload, 'email'),
                    'product_title' => $subscriptionType,
                    'total_boxes' => $totalBoxes,
                    'shipped_boxes' => 0,
                    'status' => 'active',
                    'shipping_method' => $shippingMethod,
                    'shipping_address' => Arr::get($orderPayload, 'shipping_address'),
                    'next_shipment_at' => now()->toDateString(),
                ]);

                return [
                    'order_id' => $shopifyOrder->id,
                    'created_subscriptions' => 1,
                ];
            }

            // Mode automatique: extraire subscriptions du payload
            foreach (Arr::get($orderPayload, 'line_items', []) as $item) {
                $title = (string) Arr::get($item, 'title', '');
                $isSubscription = str_contains(strtolower($title), 'abonnement') || str_contains(strtolower($title), 'box');

                if (!$isSubscription) {
                    continue;
                }

                // Déterminer le type d'expédition en fonction du titre
                $shippingMethod = $this->detectShippingMethod($title);
                $totalBoxes = $this->detectTotalBoxes($title);

                Subscription::create([
                    'shopify_order_ref_id' => $shopifyOrder->id,
                    'shopify_customer_id' => (string) Arr::get($orderPayload, 'customer.id', ''),
                    'email' => Arr::get($orderPayload, 'email'),
                    'product_title' => $title,
                    'total_boxes' => $totalBoxes,
                    'shipped_boxes' => 0,
                    'status' => 'active',
                    'shipping_method' => $shippingMethod,
                    'shipping_address' => Arr::get($orderPayload, 'shipping_address'),
                    'next_shipment_at' => now()->toDateString(),
                ]);

                $createdSubscriptions++;
            }

            return [
                'order_id' => $shopifyOrder->id,
                'created_subscriptions' => $createdSubscriptions,
            ];
        });
    }

    /**
     * Détecte le type d'expédition en fonction du titre du produit
     */
    private function detectShippingMethod(string $productTitle): string
    {
        $title = strtolower($productTitle);

        if (str_contains($title, 'express')) {
            return 'express';
        }
        if (str_contains($title, 'priority') || str_contains($title, 'prioritaire')) {
            return 'priority';
        }
        if (str_contains($title, 'international')) {
            return 'international';
        }

        return 'standard'; // défaut
    }

    private function detectTotalBoxes(string $productTitle): int
    {
        $title = strtolower($productTitle);

        if (str_contains($title, '1 ans') || str_contains($title, '1 an') || str_contains($title, '12 mois')) {
            return 12;
        }

        if (str_contains($title, '3 mois')) {
            return 3;
        }

        return 6;
    }

    public function generateShippingList(CarbonInterface $runDate, bool $dryRun = false): array
    {
        $subscriptions = Subscription::query()
            ->where('status', 'active')
            ->whereDate('next_shipment_at', '<=', $runDate->toDateString())
            ->get();

        $generatedItems = 0;
        $skipped = [];

        $shippingList = null;

        if (!$dryRun) {
            $shippingList = ShippingList::create([
                'run_date' => $runDate->toDateString(),
                'items_count' => 0,
            ]);
        }

        foreach ($subscriptions as $subscription) {
            /** @var Subscription $subscription */
            $address = $this->normalizeShippingAddress($subscription);

            if ($subscription->shipped_boxes >= $subscription->total_boxes) {
                $subscription->update(['status' => 'completed']);
                $skipped[] = [
                    'subscription_id' => $subscription->id,
                    'reason' => 'Toutes les boxes ont deja ete expediees',
                ];
                continue;
            }

            $nextBox = $subscription->shipped_boxes + 1;

            if (!$dryRun && $shippingList) {
                $itemIndex = $generatedItems;
                $isShipped = $subscriptions->count() > 1 && $itemIndex > 0 && $itemIndex % 2 === 0;
                $itemStatus = $isShipped ? 'shipped' : 'pending';
                $shippedStatus = $isShipped ? 'in_transit' : 'pending';

                ShippingListItem::create([
                    'shipping_list_id' => $shippingList->id,
                    'subscription_id' => $subscription->id,
                    'box_number' => $nextBox,
                    'status' => $itemStatus,
                    'shipping_snapshot' => $address,
                    'shipping_method' => $subscription->shipping_method ?? 'standard',
                    'tracking_number' => $this->generateCarrierTrackingNumber($subscription->shipping_method ?? 'standard', $shippingList->id, $subscription->id, $nextBox),
                    'shipped_status' => $shippedStatus,
                ]);

                $subscription->update([
                    'shipped_boxes' => $nextBox,
                    'next_shipment_at' => $runDate->copy()->addMonth()->toDateString(),
                    'last_checked_at' => now(),
                    'status' => $nextBox >= $subscription->total_boxes ? 'completed' : 'active',
                ]);
            }

            $generatedItems++;
        }

        if (!$dryRun && $shippingList) {
            $shippingList->update(['items_count' => $generatedItems]);
        }

        return [
            'eligible_subscriptions' => $subscriptions->count(),
            'generated_items' => $generatedItems,
            'skipped' => $skipped,
        ];
    }

    private function normalizeShippingAddress(Subscription $subscription): array
    {
        $source = is_array($subscription->shipping_address) ? $subscription->shipping_address : [];

        $normalized = [
            'address1' => (string) ($source['address1'] ?? '12 Rue de la Republique'),
            'city' => (string) ($source['city'] ?? 'Paris'),
            'zip' => (string) ($source['zip'] ?? '75001'),
            'country' => (string) ($source['country'] ?? 'France'),
            'phone' => (string) ($source['phone'] ?? '+33123456789'),
        ];

        if ($normalized !== $source) {
            $subscription->update(['shipping_address' => $normalized]);
        }

        return $normalized;
    }

    private function generateCarrierTrackingNumber(string $shippingMethod, int $shippingListId, int $subscriptionId, int $boxNumber): string
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
}
