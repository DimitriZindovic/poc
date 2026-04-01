<?php

use App\Http\Controllers\FrontendController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FrontendController::class, 'index']);
Route::get('/subscriptions', fn() => view('subscriptions'));
Route::get('/shopify-test', fn() => view('shopify-test'));

Route::get('/health', function () {
    return response()->json(['ok' => true]);
});


