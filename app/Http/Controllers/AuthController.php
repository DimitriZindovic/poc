<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $supabaseUrl = env('SUPABASE_URL') . '/rest/v1/users';
        $supabaseKey = env('SUPABASE_KEY');

        $response = Http::withHeaders([
            'apikey' => $supabaseKey,
            'Authorization' => 'Bearer ' . $supabaseKey,
        ])->get($supabaseUrl, [
                    'email' => 'eq.' . $request->email,
                    'select' => '*',
                ]);

        if (!$response->successful() || empty($response->json())) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $userData = $response->json()[0];

        if (!Hash::check($request->password, $userData['password_hash'])) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $customUser = new class ($userData['id'], $userData['email']) implements \Tymon\JWTAuth\Contracts\JWTSubject {
            private $id;
            private $email;

            public function __construct($id, $email)
            {
                $this->id = $id;
                $this->email = $email;
            }

            public function getJWTIdentifier()
            {
                return $this->id;
            }

            public function getJWTCustomClaims()
            {
                return ['email' => $this->email];
            }
        };

        $token = JWTAuth::fromUser($customUser);

        return response()->json([
            'token' => $token,
        ]);
    }

    public function myUser()
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload()->toArray();

            $supabaseUrl = env('SUPABASE_URL') . '/rest/v1/users';
            $supabaseKey = env('SUPABASE_KEY');

            $response = Http::withHeaders([
                'apikey' => $supabaseKey,
                'Authorization' => 'Bearer ' . $supabaseKey,
            ])->get($supabaseUrl, [
                        'id' => 'eq.' . $payload['sub'],
                        'select' => '*',
                    ]);

            if (!$response->successful() || empty($response->json())) {
                return response()->json(['error' => 'User not found'], 404);
            }

            $userData = $response->json()[0];

            return response()->json($userData);
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json(['error' => 'Token has expired'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json(['error' => 'Token is invalid'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json(['error' => 'Token is missing'], 401);
        }
    }
}
