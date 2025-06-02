<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CycleController extends Controller
{
    public function fetchCycleHistory(Request $request)
    {
        $uid = $request->get('firebase_uid');
        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }

        $projectId = config('firebase.project_id');
        $year = date('Y');
        $history = [];

        for ($monthIndex = 1; $monthIndex <= 12; $monthIndex++) {
            $month = str_pad($monthIndex, 2, '0', STR_PAD_LEFT);
            $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}/periods/{$year}/{$month}/active";

            try {
                $response = Http::get($url);

                if ($response->successful()) {
                    $data = $response->json();
                    if (!empty($data['fields'])) {
                        $fields = $data['fields'];

                        // Parsing tanggal mulai dan tanggal berakhir
                        $startDate = isset($fields['start_date']['timestampValue'])
                            ? Carbon::parse($fields['start_date']['timestampValue'])->setTimezone('Asia/Jakarta')
                            : null;

                        $endDate = isset($fields['end_date']['timestampValue'])
                            ? Carbon::parse($fields['end_date']['timestampValue'])->setTimezone('Asia/Jakarta')
                            : null;

                        // Parsing  notes (bentuk map atau array)
                        $notes = [];
                        if (isset($fields['notes']['mapValue']['fields'])) {
                            foreach ($fields['notes']['mapValue']['fields'] as $dateStr => $noteVal) {
                                $notes[$dateStr] = $noteVal['stringValue'] ?? '';
                            }
                        } elseif (isset($fields['notes']['arrayValue']['values'])) {
                            // Jika notes berupa array, konversi ke map dengan index sebagai key
                            foreach ($fields['notes']['arrayValue']['values'] as $idx => $noteVal) {
                                $notes[(string)$idx] = $noteVal['stringValue'] ?? '';
                            }
                        }

                        // Hitung lama periode dan selisih hari
                        $periodLength = $fields['periodLength']['integerValue'] ?? 0;
                        $daysDifference = $startDate ? abs((int) Carbon::now()->diffInDays($startDate)) : null;

                        // Masukkan ke history
                        $history[] = [
                            'month' => $month,
                            'startDate' => $startDate ? $startDate->format('j F Y') : null,
                            'periodLength' => $periodLength ? $periodLength . ' days' : null,
                            'daysAgo' => $daysDifference !== null ? $daysDifference . ' days ago' : null,
                            'notes' => $notes,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Error fetching period for {$year}-{$month}: " . $e->getMessage());
            }
        }

        return response()->json(['history' => $history]);
    }
    
    /**
     * Memuat berdasarkan data siklus pengguna.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function loadNotifications(Request $request)
    {
        $uid = $request->get('firebase_uid');

        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }

        $projectId = config('firebase.project_id');
        $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}";

        try {
            $response = Http::get($url);

            if (!$response->successful()) {
                return response()->json(['error' => 'Failed to retrieve user data from Firestore'], 500);
            }

            $userData = $response->json()['fields'] ?? null;
            if (!$userData) {
                return response()->json(['error' => 'User data not found'], 404);
            }

            $lastPeriodStartDate = isset($userData['lastPeriodStartDate']['timestampValue']) ? new \DateTime($userData['lastPeriodStartDate']['timestampValue']) : null;
            $lastPeriodEndDate = isset($userData['lastPeriodEndDate']['timestampValue']) ? new \DateTime($userData['lastPeriodEndDate']['timestampValue']) : null;
            $cycleLength = isset($userData['cycleLength']['integerValue']) ? (int)$userData['cycleLength']['integerValue'] : 28;

            $today = new \DateTime();
            $notifications = [];

            if ($lastPeriodStartDate) {
                $predictedStartDate = clone $lastPeriodStartDate;
                $predictedStartDate->modify("+{$cycleLength} days");
                $formattedPredictedStartDate = $predictedStartDate->format('F j, Y');

                if ($today > $predictedStartDate) {
                    if ($lastPeriodStartDate->format('m') != $today->format('m')) {
                        $daysDelayed = $today->diff($lastPeriodStartDate)->days;
                        $notifications[] = [
                            'type' => 'delayed',
                            'message' => 'Your period is delayed!',
                            'timestamp' => $today->format(\DateTime::ATOM),
                            'additionalText' => "Delayed by {$daysDelayed} days since last month."
                        ];
                    }
                }

                if ($lastPeriodEndDate && $today > $lastPeriodStartDate && $today < $lastPeriodEndDate) {
                    $daysPassed = $today->diff($lastPeriodStartDate)->days + 2;
                    $notifications[] = [
                        'type' => 'started',
                        'message' => 'Your period has started! 🌟',
                        'timestamp' => $today->format(\DateTime::ATOM),
                        'additionalText' => "Day {$daysPassed} of your cycle."
                    ];
                }

                if ($today < $predictedStartDate) {
                    $daysLeft = $predictedStartDate->diff($today)->days + 1;
                    $notifications[] = [
                        'type' => 'upcoming',
                        'message' => "Upcoming period in {$daysLeft} days 🌟",
                        'timestamp' => $today->format(\DateTime::ATOM),
                        'additionalText' => "Expected start: {$formattedPredictedStartDate}"
                    ];
                }

                if ($today > $lastPeriodStartDate->modify("+{$cycleLength} days")) {
                    $notifications[] = [
                        'type' => 'finished',
                        'message' => 'Your period has finished.',
                        'timestamp' => $today->format(\DateTime::ATOM),
                        'additionalText' => 'End of current cycle.'
                    ];
                }
            } else {
                $notifications[] = [
                    'type' => 'no_data',
                    'message' => 'No cycle data available.',
                    'timestamp' => $today->format(\DateTime::ATOM),
                    'additionalText' => 'Please log your last period date.'
                ];
            }

            return response()->json(['notifications' => $notifications]);
        } catch (\Exception $e) {
            Log::error('Error loading notifications: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred while loading notifications'], 500);
        }
    }

}
