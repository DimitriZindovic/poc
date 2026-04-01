<?php

use App\Http\Controllers\ShippingController;
use App\Http\Controllers\ShopifyController;
use Illuminate\Support\Facades\Route;

Route::get('/shopify/products', [ShopifyController::class, 'products']);
Route::get('/shopify/access-scopes', [ShopifyController::class, 'accessScopes']);
Route::get('/shopify/checkout-status', [ShopifyController::class, 'checkoutStatus']);
Route::post('/shopify/simulate-order', [ShopifyController::class, 'simulateOrder']);
Route::post('/shopify/webhooks/orders-paid', [ShopifyController::class, 'ordersPaidWebhook']);

Route::get('/shipping-lists/latest', [ShippingController::class, 'latest']);
