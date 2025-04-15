<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

class UserController extends Controller
{
    public function index()
    {
        $supabaseUrl = env('SUPABASE_URL') . '/rest/v1/users';

        $supabaseKey = env('SUPABASE_KEY');

        $response = Http::withHeaders([
            'apikey' => $supabaseKey,
            'Authorization' => 'Bearer ' . $supabaseKey,
        ])->get($supabaseUrl);

        if ($response->successful()) {
            return response()->json($response->json());
        }

        return response()->json([
            'error' => 'Failed to fetch users from Supabase',
            'details' => $response->body(),
        ], $response->status());
    }
}
