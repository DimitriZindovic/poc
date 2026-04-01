<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingListItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipping_list_id',
        'subscription_id',
        'box_number',
        'status',
        'skip_reason',
        'shipping_snapshot',
    ];

    protected $casts = [
        'shipping_snapshot' => 'array',
    ];

    public function shippingList()
    {
        return $this->belongsTo(ShippingList::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
