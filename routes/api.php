<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Middleware\CheckPermissions;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\AuthorizeJWT;

Route::post('/webhooks/shopify-sales', [ProductController::class, 'handleShopifySalesWebhook']);
Route::post('/register', [RegisterController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware([AuthorizeJWT::class, CheckPermissions::class . ':can_get_my_user'])->group(function () {
    Route::get('/my-user', [AuthController::class, 'myUser']);
});

Route::middleware([AuthorizeJWT::class, CheckPermissions::class . ':can_get_users'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
});

Route::middleware([AuthorizeJWT::class, CheckPermissions::class . ':can_post_products'])->group(function () {
    Route::post('/products', [ProductController::class, 'store']);
});

Route::middleware([AuthorizeJWT::class, CheckPermissions::class . ':can_get_my_products'])->group(function () {
    Route::get('/my-products', [ProductController::class, 'myProducts']);
});

Route::middleware([AuthorizeJWT::class, CheckPermissions::class . ':can_get_products'])->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
});
