<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Auth;
use GuzzleHttp\Client;
use Google\Auth\ApplicationDefaultCredentials;

class AuthController extends Controller
{
    protected $auth;
    protected $projectId;
    protected $firebaseConfigPath;
    protected $apiKey; // Firebase Web API Key (untuk login via REST)

    public function __construct()
    {
        $this->firebaseConfigPath = config('firebase.credentials');
        $this->projectId = config('firebase.project_id');
        $this->apiKey = config('firebase.api_key');

        if (!file_exists($this->firebaseConfigPath)) {
            throw new \Exception('Firebase credentials file not found.');
        }

        $this->auth = app('firebase.auth'); // Proper way to inject Firebase Auth
    }

    private function getAccessToken()
    {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->firebaseConfigPath);
        $scope = 'https://www.googleapis.com/auth/datastore';

        $auth = ApplicationDefaultCredentials::getCredentials($scope);
        $token = $auth->fetchAuthToken();

        if (!isset($token['access_token'])) {
            throw new \Exception('Failed to get access token');
        }

        return $token['access_token'];
    }

    private function formatValue($value)
    {
        if (is_int($value)) {
            return ['integerValue' => (string)$value];
        } elseif (is_string($value)) {
            return ['stringValue' => $value];
        } elseif (is_bool($value)) {
            return ['booleanValue' => $value];
        } elseif ($value === null) {
            return ['nullValue' => null];
        } elseif ($value instanceof \DateTime) {
            return ['timestampValue' => $value->format('Y-m-d\TH:i:s.u\Z')];
        }

        throw new \Exception('Unsupported data type');
    }

    private function saveToFirestore($path, array $data, $accessToken, $method = 'PATCH')
    {
        $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents/{$path}";

        $formattedData = ['fields' => []];
        foreach ($data as $key => $value) {
            $formattedData['fields'][$key] = $this->formatValue($value);
        }

        $client = new Client();

        $response = $client->request($method, $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => $formattedData,
        ]);

        return json_decode($response->getBody(), true);
    }

    private function savePartialToFirestore(
        string $path,
        array $data,
        string $accessToken,
        array $fieldPaths
    ) {
        $url  = "https://firestore.googleapis.com/v1/projects/{$this->projectId}"
            . "/databases/(default)/documents/{$path}";

        $queryParts = [];
        foreach ($fieldPaths as $field) {
            $queryParts[] = 'updateMask.fieldPaths=' . urlencode($field);
        }
        $url .= '?' . implode('&', $queryParts);

        $body = ['fields' => []];
        foreach ($data as $k => $v) {
            $body['fields'][$k] = $this->formatValue($v);
        }

        $client = new \GuzzleHttp\Client();

        $response = $client->request('PATCH', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type'  => 'application/json',
            ],
            'json' => $body,
        ]);

        return json_decode($response->getBody(), true);
    }

    // Register user
    public function register(Request $request)
    {
        $request->validate([
            'fullname' => 'required|string|min:3',
            'email'    => 'required|email|unique:users,email', // optional unique in local DB
            'password' => 'required|min:6',
        ]);

        try {
            // Create user di Firebase Auth
            $user = $this->auth->createUser([
                'email'       => $request->email,
                'password'    => $request->password,
                'displayName' => $request->fullname,
            ]);

            $userData = [
                'fullname'           => $request->fullname,
                'email'              => $request->email,
                'created_at'         => new \DateTime(),
                'cycleLength'        => 27,
                'lastPeriodStartDate' => null,
                'lastPeriodEndDate'  => null,
            ];

            $accessToken = $this->getAccessToken();

            $this->saveToFirestore("users/{$user->uid}", $userData, $accessToken);

            return response()->json([
                'message' => 'User registered successfully',
                'uid'     => $user->uid,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Firebase Register Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Login user
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);

        try {
            // Panggil Firebase Auth REST API untuk login
            $client = new Client();
            $resp = $client->post(
                "https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key={$this->apiKey}",
                ['json' => [
                    'email'             => $request->email,
                    'password'          => $request->password,
                    'returnSecureToken' => true,
                ]]
            );
            $body = json_decode($resp->getBody(), true);
            $uid     = $body['localId'];
            $idToken = $body['idToken'];

            // Cek ada cycle data?
            $accessToken = $this->getAccessToken();
            $client = new Client();
            $check = $client->get(
                "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents/users/{$uid}?mask.fieldPaths=cycleLength&mask.fieldPaths=lastPeriodStartDate&mask.fieldPaths=lastPeriodEndDate",
                ['headers' => ['Authorization' => 'Bearer ' . $accessToken]]
            );
            $doc = json_decode($check->getBody(), true);
            $fields = $doc['fields'] ?? [];
            $hasCycleData = (
                isset($fields['lastPeriodStartDate']['timestampValue']) &&
                isset($fields['lastPeriodEndDate']['timestampValue']) &&
                !empty($fields['lastPeriodStartDate']['timestampValue']) &&
                !empty($fields['lastPeriodEndDate']['timestampValue'])
            );




            return response()->json([
                'uid'          => $uid,
                'idToken'      => $idToken,
                'hasCycleData' => $hasCycleData,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Login Error: ' . $e->getMessage());
            return response()->json(['error' => 'Authentication failed'], 401);
        }
    }

    /**
     * POST /api/cycle
     * Body: cycleLength, lastPeriodStartDate, lastPeriodEndDate (ISO8601)
     */
    public function saveCycleData(Request $request)
    {
        $request->validate([
            'cycleLength'         => 'required|integer',
            'lastPeriodStartDate' => 'required|date',
            'lastPeriodEndDate'   => 'required|date|after_or_equal:lastPeriodStartDate',
        ]);

        // Ambil uid dari user yang udah login (pastiin middleware auth udah pasang)
        $uid = $request->input('firebase_uid');

        if (!$uid) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $start = new \DateTime($request->lastPeriodStartDate);
        $end   = new \DateTime($request->lastPeriodEndDate);
        $periodLen = $end->diff($start)->days + 1;

        $accessToken = $this->getAccessToken();

        // 1) Simpan ke dokumen user utama
        $this->savePartialToFirestore(
            "users/{$uid}",
            [
                'cycleLength'         => $request->cycleLength,
                'lastPeriodStartDate' => $start,
                'lastPeriodEndDate'   => $end,
                'periodLength'        => $periodLen,
            ],
            $accessToken,
            [
                'cycleLength',
                'lastPeriodStartDate',
                'lastPeriodEndDate',
                'periodLength',
            ]
        );



        // 2) Simpan ke subcollection periods/{year}/{month}/active
        $year  = $start->format('Y');
        $month = $start->format('m');
        $this->saveToFirestore(
            "users/{$uid}/periods/{$year}/{$month}/active",
            [
                'start_date'    => $start,
                'end_date'      => $end,
                'periodLength'  => $periodLen,
                'notes'        => null,
            ],
            $accessToken,
            'PATCH'
        );

        // 3) Hitung prediksi next period
        $predStart = (clone $start)->modify("+{$request->cycleLength} days");
        $predEnd   = (clone $predStart)->modify("+" . ($periodLen - 1) . " days");
        $pYear     = $predStart->format('Y');
        $pMonth    = $predStart->format('m');

        // 4) Simpan predictions/{year}/{month}/active
        $this->saveToFirestore(
            "users/{$uid}/predictions/{$pYear}/{$pMonth}/active",
            [
                'predicted_start' => $predStart,
                'predicted_end'   => $predEnd,
                'created_at'      => new \DateTime(),
                'is_confirmed'    => false,
            ],
            $accessToken,
            'PATCH'
        );

        return response()->json(['message' => 'Cycle data saved'], 200);
    }
}
