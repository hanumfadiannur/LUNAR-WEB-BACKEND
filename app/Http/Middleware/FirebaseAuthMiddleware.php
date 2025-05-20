<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use GuzzleHttp\Client;

use GuzzleHttp\Exception\RequestException;

use Illuminate\Support\Facades\Log;

class FirebaseAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        Log::info('Middleware triggered.');

        $idToken = $request->bearerToken();
        Log::info('Bearer Token: ' . $idToken);

        if (! $idToken) {
            Log::warning('Unauthorized - No token provided');
            return response()->json(['error' => 'Unauthorized - No token provided'], 401);
        }

        try {
            Log::info('Verifying token...');
            $client = new \GuzzleHttp\Client();
            $response = $client->post('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . env('FIREBASE_API_KEY'), [
                'json' => ['idToken' => $idToken]
            ]);

            $user = json_decode($response->getBody(), true);
            Log::info('User data from Firebase:', $user);

            if (empty($user['users'][0]['localId'])) {
                Log::warning('Invalid token - No localId found');
                return response()->json(['error' => 'Invalid token - No localId found'], 401);
            }

            $firebaseUid = $user['users'][0]['localId'];
            Log::info('Firebase UID: ' . $firebaseUid);

            // Simpan UID ke dalam request untuk digunakan di controller
            $request->merge(['firebase_uid' => $firebaseUid]);
        } catch (\Exception $e) {
            Log::error('Error verifying token: ' . $e->getMessage());
            return response()->json(['error' => 'Unauthorized - Token verification failed'], 401);
        }

        Log::info('Token verification successful. Proceeding to next middleware...');
        return $next($request);
    }
}
