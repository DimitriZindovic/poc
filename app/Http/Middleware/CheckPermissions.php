<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class CheckPermissions
{
    public function handle(Request $request, Closure $next, $permission)
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
                        'select' => 'role_id',
                    ]);

            if (!$response->successful() || empty($response->json())) {
                Log::error('User not found or invalid response from Supabase');
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $roleId = $response->json()[0]['role_id'] ?? null;

            if (!$roleId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $supabaseUrl = env('SUPABASE_URL') . '/rest/v1/roles';
            $response = Http::withHeaders([
                'apikey' => $supabaseKey,
                'Authorization' => 'Bearer ' . $supabaseKey,
            ])->get($supabaseUrl, [
                        'id' => 'eq.' . $roleId,
                        'select' => $permission,
                    ]);

            return $next($request);
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            Log::error('Token expired:', ['exception' => $e->getMessage()]);
            return response()->json(['error' => 'Token has expired'], 401);
        }
    }
}
