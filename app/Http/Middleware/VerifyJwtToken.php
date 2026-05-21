<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class VerifyJwtToken
{
    public function handle(Request $request, Closure $next)
    {
        // Allow OPTIONS requests for CORS preflight
        if ($request->getMethod() === 'OPTIONS') {
            return $next($request);
        }

        // Cookie (same-site) or Authorization header (local dev / cross-origin)
        $token = $request->cookie('access_token');
        if (!$token) {
            $authHeader = (string) $request->header('Authorization', '');
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $token = trim($matches[1]);
            }
        }

        if (!$token) {
            \Log::warning('No access_token cookie or Bearer token found');
            return response()->json(['message' => 'Unauthorized - No token'], 401);
        }

        try {
            // Parse and authenticate token using tymon/jwt-auth
            $user = JWTAuth::setToken($token)->authenticate();
            if (!$user) {
                \Log::warning('User not found for token');
                return response()->json(['message' => 'User not found'], 404);
            }

            // Log in user for the request
            Auth::login($user);

            // Optionally attach payload to request
            $payload = JWTAuth::payload();
            $request->merge(['jwt_payload' => (array) $payload]);

        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            \Log::error('Token expired: ' . $e->getMessage());
            return response()->json(['message' => 'Token expired'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            \Log::error('Invalid token: ' . $e->getMessage());
            return response()->json(['message' => 'Invalid token'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            \Log::error('JWT error: ' . $e->getMessage());
            return response()->json(['message' => 'Unauthorized - Token error'], 401);
        }

        return $next($request);
    }
}