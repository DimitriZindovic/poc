<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_order_ref_id')->constrained('shopify_orders')->cascadeOnDelete();
            $table->string('shopify_customer_id')->nullable();
            $table->string('email')->nullable();
            $table->string('product_title');
            $table->unsignedInteger('total_boxes')->default(6);
            $table->unsignedInteger('shipped_boxes')->default(0);
            $table->string('status')->default('active');
            $table->json('shipping_address')->nullable();
            $table->date('next_shipment_at');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
