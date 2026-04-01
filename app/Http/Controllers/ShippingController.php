<?php

namespace App\Http\Controllers;

use App\Models\ShippingList;

class ShippingController extends Controller
{
    public function latest()
    {
        $latest = ShippingList::query()
            ->with(['items.subscription'])
            ->latest('id')
            ->first();

        if (!$latest) {
            return response()->json(['shipping_list' => null]);
        }

        return response()->json([
            'shipping_list' => [
                'id' => $latest->id,
                'run_date' => (string) $latest->run_date,
                'items_count' => $latest->items_count,
                'items' => $latest->items->map(function ($item) {
                    return [
                        'subscription_id' => $item->subscription_id,
                        'box_number' => $item->box_number,
                        'status' => $item->status,
                        'email' => $item->subscription?->email,
                        'product_title' => $item->subscription?->product_title,
                        'address' => $item->shipping_snapshot,
                    ];
                }),
            ],
        ]);
    }
}
