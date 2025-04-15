<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use GuzzleHttp\Client;

class RegisterController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $client = new Client([
            'base_uri' => env('SUPABASE_URL'),
            'headers' => [
                'apikey' => env('SUPABASE_KEY'),
                'Authorization' => 'Bearer ' . env('SUPABASE_KEY'),
                'Content-Type' => 'application/json',
            ],
        ]);

        $passwordHash = Hash::make($request->password);

        $response = $client->post('/rest/v1/users', [
            'json' => [
                'name' => $request->name,
                'email' => $request->email,
                'password_hash' => $passwordHash,
            ],
        ]);

        if ($response->getStatusCode() === 201) {
            return response()->json(['message' => 'User registered successfully'], 201);
        }

        return response()->json(['error' => 'Failed to register user'], 500);
    }
}
