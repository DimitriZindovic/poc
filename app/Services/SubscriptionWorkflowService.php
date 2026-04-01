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
    public function ingestPaidOrder(array $orderPayload): array
    {
        return DB::transaction(function () use ($orderPayload) {
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

            foreach (Arr::get($orderPayload, 'line_items', []) as $item) {
                $title = (string) Arr::get($item, 'title', '');
                $isSubscription = str_contains(strtolower($title), 'abonnement') || str_contains(strtolower($title), 'box');

                if (!$isSubscription) {
                    continue;
                }

                Subscription::create([
                    'shopify_order_ref_id' => $shopifyOrder->id,
                    'shopify_customer_id' => (string) Arr::get($orderPayload, 'customer.id', ''),
                    'email' => Arr::get($orderPayload, 'email'),
                    'product_title' => $title,
                    'total_boxes' => 6,
                    'shipped_boxes' => 0,
                    'status' => 'active',
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
            $address = $subscription->shipping_address;

            if (empty($address['address1']) || empty($address['city']) || empty($address['zip']) || empty($address['country'])) {
                $skipped[] = [
                    'subscription_id' => $subscription->id,
                    'reason' => 'Adresse incomplète',
                ];
                continue;
            }

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
                ShippingListItem::create([
                    'shipping_list_id' => $shippingList->id,
                    'subscription_id' => $subscription->id,
                    'box_number' => $nextBox,
                    'status' => 'pending',
                    'shipping_snapshot' => $address,
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
}
