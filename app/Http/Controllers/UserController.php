<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    protected $projectId;
    protected $firebaseConfigPath;
    protected $accessToken;

    public function __construct()
    {
        $this->firebaseConfigPath = config('firebase.credentials');
        $this->projectId = config('firebase.project_id');
        $this->accessToken = $this->getAccessToken();
    }

    /**
     * Get access token from Google Cloud.
     *
     * @return string
     * @throws \Exception
     */
    private function getAccessToken()
    {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->firebaseConfigPath);
        $scope = 'https://www.googleapis.com/auth/datastore';
        $auth = \Google\Auth\ApplicationDefaultCredentials::getCredentials($scope);
        $token = $auth->fetchAuthToken();

        if (!isset($token['access_token'])) {
            throw new \Exception('Failed to get access token');
        }

        return $token['access_token'];
    }

    /**
     * Fetch user data from Firestore.
     *
     * @param string $uid
     * @return array|null
     */
    private function fetchUserDataFromFirestore($uid)
    {
        $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents/users/{$uid}";

        $client = new Client();
        $response = $client->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
            ],
        ]);

        $body = json_decode($response->getBody(), true);
        return $body['fields'] ?? null;
    }

    /**
     * Update user's full name in Firestore.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateFullName(Request $request)
    {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'firebase_uid' => 'required|string',
            'fullname' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        $uid = $request->get('firebase_uid');
        $newFullName = $request->get('fullname');

        $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents/users/{$uid}?updateMask.fieldPaths=fullname";

        $client = new Client();

        try {
            $response = $client->request('PATCH', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'fields' => [
                        'fullname' => [
                            'stringValue' => $newFullName,
                        ],
                    ],
                ],
            ]);

            return response()->json(['message' => 'Full name updated successfully.']);
        } catch (\Exception $e) {
            Log::error("Failed to update full name: " . $e->getMessage());
            return response()->json(['error' => 'Failed to update full name.'], 500);
        }
    }
    /**
     * Get cycle status for a user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCycleStatus(Request $request)
    {
        $uid = $request->get('firebase_uid');

        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }

        try {
            $userDataRaw = $this->fetchUserDataFromFirestore($uid);
            if (!$userDataRaw) {
                return response()->json(['error' => 'User data not found'], 404);
            }

            // Extract fields safely
            $fullname = $userDataRaw['fullname']['stringValue'] ?? "User";
            $email = $userDataRaw['email']['stringValue'] ?? null;
            $cycleLength = isset($userDataRaw['cycleLength']['integerValue']) ? (int)$userDataRaw['cycleLength']['integerValue'] : 28;
            $periodLength = isset($userDataRaw['periodLength']['integerValue']) ? (int)$userDataRaw['periodLength']['integerValue'] : 5;

            $lastPeriodStartDate = isset($userDataRaw['lastPeriodStartDate']['timestampValue']) ? new \DateTime($userDataRaw['lastPeriodStartDate']['timestampValue']) : null;
            $lastPeriodEndDate = isset($userDataRaw['lastPeriodEndDate']['timestampValue']) ? new \DateTime($userDataRaw['lastPeriodEndDate']['timestampValue']) : null;

            $today = new \DateTime();

            $result = [
                'fullname' => $fullname,
                'cycleLength' => $cycleLength,
                'periodLength' => $periodLength,
                'email' => $email,

            ];

            if ($lastPeriodStartDate) {
                $predictedStartDate = clone $lastPeriodStartDate;
                $predictedStartDate->modify("+{$cycleLength} days");

                $formattedPredictedStartDate = $predictedStartDate->format('F j, Y');

                if ($today > $predictedStartDate) {
                    if ($lastPeriodStartDate->format('m') != $today->format('m')) {
                        $daysDelayed = $today->diff($lastPeriodStartDate)->days;
                        $result['currentCycleMessage'] = "Your period is delayed!";
                        $result['currentCycleStatus'] = "Delayed by {$daysDelayed} days since last month.";
                    }
                } elseif ($today > $lastPeriodStartDate && $lastPeriodEndDate && $today < $lastPeriodEndDate) {
                    $daysPassed = $today->diff($lastPeriodStartDate)->days + 2;
                    $result['currentCycleMessage'] = "Your period has started!";
                    $result['currentCycleStatus'] = "Day {$daysPassed}";
                } elseif ($today->format('m') == $lastPeriodStartDate->format('m') && $today < $predictedStartDate) {
                    $result['currentCycleMessage'] = "Your period has ended!";
                    $result['currentCycleStatus'] = "Your period is expected to begin on, {$formattedPredictedStartDate}";
                } elseif ($today > $lastPeriodStartDate->modify("+{$cycleLength} days")) {
                    $result['currentCycleMessage'] = "Your period is finished.";
                    $result['currentCycleStatus'] = "End of cycle.";
                } else {
                    $daysLeft = $predictedStartDate->diff($today)->days + 1;
                    $result['currentCycleMessage'] = "Upcoming period in {$daysLeft} days 🌟";
                    $result['currentCycleStatus'] = "Your next period is expected to start on, {$formattedPredictedStartDate}";
                }
            } else {
                $result['currentCycleMessage'] = "No cycle data available.";
                $result['currentCycleStatus'] = "Please log your last period date.";
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Error fetching cycle status: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get cycle status'], 500);
        }
    }
}
