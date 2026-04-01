<?php

namespace App\Console\Commands;

use App\Models\ShippingList;
use App\Models\ShippingListItem;
use App\Models\Subscription;
use App\Models\ShopifyOrder;
use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateTestData extends Command
{
    protected $signature = 'app:generate-test-data {--count=5 : Nombre de commandes à générer} {--reset : Supprimer les données existantes avant}';
    protected $description = 'Génère plusieurs commandes test avec différents statuts pour tester le MVP';

    public function handle()
    {
        if ($this->option('reset')) {
            $this->info('Suppression des données test existantes...');
            DB::transaction(function () {
                ShippingListItem::truncate();
                ShippingList::truncate();
                Subscription::truncate();
                ShopifyOrder::truncate();
            });
            $this->info('✓ Données supprimées');
        }

        $count = (int) $this->option('count');
        $emails = [
            'alice@example.com',
            'bob.smith@example.com',
            'charlie.brown@example.com',
            'diana.prince@example.com',
            'eve.johnson@example.com',
            'frank.forest@example.com',
            'martin.dupont@example.com',
            'sarah.bernard@example.com',
            'leo.moreau@example.com',
            'nina.laurent@example.com',
            'hugo.martin@example.com',
            'julie.renaud@example.com',
        ];

        $customerNames = [
            'Alice Martin',
            'Bob Smith',
            'Charlie Brown',
            'Diana Prince',
            'Eve Johnson',
            'Frank Forest',
            'Martin Dupont',
            'Sarah Bernard',
            'Leo Moreau',
            'Nina Laurent',
            'Hugo Martin',
            'Julie Renaud',
        ];

        $productTitles = $this->resolveTestProductTitles();
        $statusPattern = ['active', 'active', 'paused', 'completed', 'active'];

        $this->info("Génération de $count commandes test...\n");

        DB::transaction(function () use ($count, $emails, $customerNames, $productTitles, $statusPattern) {
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

                // Créer shopify_order
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

                // Créer subscription
                $subscription = Subscription::create([
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

                $statusLabel = match ($status) {
                    'active' => '✓ Actif',
                    'paused' => '⏸ Pausé',
                    'completed' => '✓ Complété',
                    default => $status,
                };

                $this->line("  [$i] {$subscription->email} - {$statusLabel} ({$shippedBoxes}/{$totalBoxes} boxes) - {$shippingMethod}");
            }
        });

        $this->info("\n✓ $count commandes générées avec succès!");
        $this->line('Produits utilisés: ' . implode(' | ', $productTitles));
        $this->info('Lancer: php artisan app:generate-shipping-list');
        $this->info('Ou ouvrir: http://127.0.0.1:8000/subscriptions');
    }

    private function resolveTestProductTitles(): array
    {
        try {
            /** @var ShopifyService $shopifyService */
            $shopifyService = app(ShopifyService::class);
            $products = $shopifyService->listProducts();

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
            Log::warning('Impossible de récupérer les produits Shopify pour GenerateTestData', [
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
}
