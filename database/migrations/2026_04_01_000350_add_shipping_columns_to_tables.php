<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Ajouter colonnes manquantes à subscriptions
        if (Schema::hasTable('subscriptions') && !Schema::hasColumn('subscriptions', 'shipping_method')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->string('shipping_method')->default('standard')->after('status');
                $table->text('shipping_notes')->nullable()->after('shipping_method');
            });
        }

        // Ajouter colonnes manquantes à shipping_list_items
        if (Schema::hasTable('shipping_list_items')) {
            if (!Schema::hasColumn('shipping_list_items', 'shipping_method')) {
                Schema::table('shipping_list_items', function (Blueprint $table) {
                    $table->string('shipping_method')->default('standard')->after('box_number');
                });
            }
            if (!Schema::hasColumn('shipping_list_items', 'tracking_number')) {
                Schema::table('shipping_list_items', function (Blueprint $table) {
                    $table->string('tracking_number')->nullable()->after('shipping_method');
                });
            }
            if (!Schema::hasColumn('shipping_list_items', 'shipped_status')) {
                Schema::table('shipping_list_items', function (Blueprint $table) {
                    $table->string('shipped_status')->default('pending')->after('tracking_number');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'shipping_method')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn(['shipping_method', 'shipping_notes']);
            });
        }

        if (Schema::hasTable('shipping_list_items')) {
            Schema::table('shipping_list_items', function (Blueprint $table) {
                if (Schema::hasColumn('shipping_list_items', 'shipping_method')) {
                    $table->dropColumn('shipping_method');
                }
                if (Schema::hasColumn('shipping_list_items', 'tracking_number')) {
                    $table->dropColumn('tracking_number');
                }
                if (Schema::hasColumn('shipping_list_items', 'shipped_status')) {
                    $table->dropColumn('shipped_status');
                }
            });
        }
    }
};
