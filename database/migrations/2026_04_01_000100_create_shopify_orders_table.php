<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopify_orders', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_order_id')->unique();
            $table->string('email')->nullable();
            $table->string('customer_name')->nullable();
            $table->json('shipping_address')->nullable();
            $table->string('financial_status')->nullable();
            $table->json('raw_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_orders');
    }
};
