<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingList extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_date',
        'items_count',
    ];

    protected $casts = [
        'run_date' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(ShippingListItem::class);
    }
}
