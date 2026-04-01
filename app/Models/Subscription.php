<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_order_ref_id',
        'shopify_customer_id',
        'email',
        'product_title',
        'total_boxes',
        'shipped_boxes',
        'status',
        'shipping_address',
        'next_shipment_at',
        'last_checked_at',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'next_shipment_at' => 'date',
        'last_checked_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(ShopifyOrder::class, 'shopify_order_ref_id');
    }
}
