<?php

use App\Services\SubscriptionWorkflowService;
use Illuminate\Support\Facades\Artisan;

Artisan::command('app:generate-shipping-list {--dry-run}', function () {
    /** @var SubscriptionWorkflowService $workflow */
    $workflow = app(SubscriptionWorkflowService::class);
    $result = $workflow->generateShippingList(now(), (bool) $this->option('dry-run'));

    $this->info('Shipping list generation done.');
    $this->line('Eligible subscriptions: ' . $result['eligible_subscriptions']);
    $this->line('Generated items: ' . $result['generated_items']);

    if (!empty($result['skipped'])) {
        $this->newLine();
        $this->warn('Skipped subscriptions:');
        foreach ($result['skipped'] as $skip) {
            $this->line('- Subscription #' . $skip['subscription_id'] . ': ' . $skip['reason']);
        }
    }
})->purpose('Generate shipping list for active subscriptions');
