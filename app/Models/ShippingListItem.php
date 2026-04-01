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
        'shipping_method',
        'tracking_number',
        'shipped_status',
    ];

    protected $casts = [
        'shipping_snapshot' => 'array',
    ];

    // Statuts d'expédition possibles
    public static function STATUSES(): array
    {
        return [
            'pending' => 'En attente',
            'packed' => 'Emballée',
            'shipped' => 'Expédiée',
            'in_transit' => 'En transit',
            'delivered' => 'Livrée',
            'failed' => 'Retour/Échec',
        ];
    }

    public function shippingList()
    {
        return $this->belongsTo(ShippingList::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
