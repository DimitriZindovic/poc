<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_order_id',
        'email',
        'customer_name',
        'shipping_address',
        'financial_status',
        'raw_payload',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'raw_payload' => 'array',
    ];
}
