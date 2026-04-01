<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_list_id')->constrained('shipping_lists')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->unsignedInteger('box_number');
            $table->string('status')->default('pending');
            $table->string('skip_reason')->nullable();
            $table->json('shipping_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['shipping_list_id', 'subscription_id', 'box_number'], 'shipping_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_list_items');
    }
};
