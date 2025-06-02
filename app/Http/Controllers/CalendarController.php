<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Auth\ApplicationDefaultCredentials;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Http;

class CalendarController extends Controller
{
    protected $firestore;
    protected $projectId;
    protected $accessToken;
    protected $firebaseConfigPath;



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
        $auth = ApplicationDefaultCredentials::getCredentials($scope);
        $token = $auth->fetchAuthToken();

        if (!isset($token['access_token'])) {
            throw new \Exception('Failed to get access token');
        }

        return $token['access_token'];
    }



    public function fetchAllPeriodEvents(Request $request)
    {
        $uid = $request->get('firebase_uid');
        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }

        $projectId = config('firebase.project_id');
        $year = date('Y');
        $events = [];

        for ($monthIndex = 1; $monthIndex <= 12; $monthIndex++) {
            $month = str_pad($monthIndex, 2, '0', STR_PAD_LEFT);
            $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}/periods/{$year}/{$month}/active";

            try {
                $response = Http::get($url);

                if ($response->successful()) {
                    $data = $response->json();
                    if (!empty($data['fields'])) {
                        $fields = $data['fields'];

                        $startDate = isset($fields['start_date']['timestampValue'])
                            ? \Carbon\Carbon::parse($fields['start_date']['timestampValue'])->setTimezone('Asia/Jakarta')->toDateString()
                            : null;

                        $endDate = isset($fields['end_date']['timestampValue'])
                            ? \Carbon\Carbon::parse($fields['end_date']['timestampValue'])->setTimezone('Asia/Jakarta')->toDateString()
                            : null;

                        // Ambil notes, bisa kosong
                        $notes = [];
                        if (isset($fields['notes']['mapValue']['fields'])) {
                            foreach ($fields['notes']['mapValue']['fields'] as $dateStr => $noteVal) {
                                $notes[$dateStr] = $noteVal['stringValue'] ?? '';
                            }
                        }

                        // Tambah event start
                        if ($startDate) {
                            $events[] = [
                                'type' => 'start',
                                'date' => $startDate,
                                'notes' => $notes[$startDate] ?? '',
                            ];
                        }

                        // Tambah event end
                        if ($endDate) {
                            $events[] = [
                                'type' => 'end',
                                'date' => $endDate,
                                'notes' => $notes[$endDate] ?? '',
                            ];
                        }

                        // Tambah event noteOnly kecuali start dan end
                        foreach ($notes as $dateStr => $note) {
                            if ($dateStr !== $startDate && $dateStr !== $endDate) {
                                $events[] = [
                                    'type' => 'noteOnly',
                                    'date' => $dateStr,
                                    'notes' => $note,
                                ];
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Dokumen tidak ada atau error lainnya
                Log::warning("Error fetching period for {$year}-{$month}: " . $e->getMessage());
            }
        }

        return response()->json(['events' => $events]);
    }

    /**
     * Fetch the next period prediction.
     */
    public function fetchNextPeriodPrediction(Request $request)
    {
        $uid = $request->get('firebase_uid');
        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }

        $projectId = config('firebase.project_id');

        // 1. Ambil data pengguna dari Firestore
        $userUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}";
        $userResponse = Http::get($userUrl);

        if (!$userResponse->successful()) {
            return response()->json(['error' => 'No user data found.'], 404);
        }

        $userData = $userResponse->json();
        $fields = $userData['fields'] ?? [];

        if (empty($fields['lastPeriodStartDate']['timestampValue']) || empty($fields['cycleLength']['integerValue'])) {
            return response()->json(['error' => 'Missing lastPeriodStartDate or cycleLength.'], 400);
        }

        // 2. Hitung prediksi
        $lastStart = \Carbon\Carbon::parse($fields['lastPeriodStartDate']['timestampValue']);
        $cycleLength = (int)$fields['cycleLength']['integerValue'];
        $predictedStart = $lastStart->copy()->addDays($cycleLength);

        $year = $predictedStart->format('Y');
        $month = $predictedStart->format('m');

        // 3. Fetch prediction document
        $predictionUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}/predictions/{$year}/{$month}/active";
        $predictionResponse = Http::get($predictionUrl);

        if (!$predictionResponse->successful()) {
            return response()->json(['error' => 'No prediction available.'], 404);
        }

        $data = $predictionResponse->json();
        $fields = $data['fields'] ?? [];

        $predictedStartDate = isset($fields['predicted_start']['timestampValue'])
            ? \Carbon\Carbon::parse($fields['predicted_start']['timestampValue'])->setTimezone('Asia/Jakarta')->toDateString()
            : null;
        $predictedEndDate = isset($fields['predicted_end']['timestampValue'])
            ? \Carbon\Carbon::parse($fields['predicted_end']['timestampValue'])->setTimezone('Asia/Jakarta')->toDateString()
            : null;

        $notes = [];
        if (isset($fields['notes']['mapValue']['fields'])) {
            foreach ($fields['notes']['mapValue']['fields'] as $dateStr => $noteVal) {
                $notes[$dateStr] = $noteVal['stringValue'] ?? '';
            }
        }

        $events = [];

        if ($predictedStartDate) {
            $events[] = [
                'type' => 'predicted_start',
                'date' => $predictedStartDate,
                'notes' => $notes[$predictedStartDate] ?? 'Predicted start of next period',
            ];
        }

        if ($predictedEndDate) {
            $events[] = [
                'type' => 'predicted_end',
                'date' => $predictedEndDate,
                'notes' => $notes[$predictedEndDate] ?? 'Predicted end of next period',
            ];
        }

        // Tambah notes tambahan jika ada
        foreach ($notes as $dateStr => $note) {
            if ($dateStr !== $predictedStartDate && $dateStr !== $predictedEndDate) {
                $events[] = [
                    'type' => 'noteOnly',
                    'date' => $dateStr,
                    'notes' => $note,
                ];
            }
        }

        return response()->json(['events' => $events]);
    }


    /**
     * Add an event to the calendar.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addEvent(Request $request)
    {
        $uid = $request->get('firebase_uid');
        if (!$uid) {
            Log::warning('Missing firebase_uid in request');
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }

        Log::info("Adding event for UID: $uid");

        $date = $request->date;
        $eventType = $request->eventType;
        $cycleLengthFromRequest = $request->cycleLength;
        $note = $request->note;

        Log::info("EventType: $eventType | Date: $date | Note: $note");

        $projectId = config('firebase.project_id');
        $userDocUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}";

        $carbonDate = \Carbon\Carbon::parse($date);
        $year = $carbonDate->format('Y');
        $month = $carbonDate->format('m');
        $normalizedDate = $carbonDate->format('Y-m-d');

        $docUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}/periods/{$year}/{$month}/active";

        $fields = [];

        if ($eventType === 'start') {
            $existingDoc = Http::withToken($this->accessToken)->get($docUrl)->json();
            $existingNotes = $existingDoc['fields']['notes']['mapValue']['fields'] ?? [];
            $existingNotes[$normalizedDate] = ['stringValue' => $note ?? ''];

            $fields['start_date'] = ['timestampValue' => $carbonDate->toIso8601String()];
            $fields['notes'] = [
                'mapValue' => [
                    'fields' => $existingNotes,
                ],
            ];

            $cycleLength = $cycleLengthFromRequest
                ? intval($cycleLengthFromRequest)
                : (int) round($this->updateCycleLength($uid, $carbonDate));


            // Update cycleLength di user document
            $userResponse = Http::withToken($this->accessToken)->patch(
                $userDocUrl . '?updateMask.fieldPaths=cycleLength&updateMask.fieldPaths=lastPeriodStartDate',
                [
                    'fields' => [
                        'cycleLength' => ['integerValue' => $cycleLength],
                        'lastPeriodStartDate' => ['timestampValue' => $carbonDate->toIso8601String()],
                    ],
                ]
            );


            Log::info("User doc cycleLength & lastPeriodStartDate update response", $userResponse->json());
        } elseif ($eventType === 'end') {
            $existingDoc = Http::withToken($this->accessToken)->get($docUrl)->json();
            $existingNotes = $existingDoc['fields']['notes']['mapValue']['fields'] ?? [];


            $existingNotes[$normalizedDate] = ['stringValue' => $note ?? ''];

            $fields['end_date'] = ['timestampValue' => $carbonDate->toIso8601String()];
            $fields['notes'] = [
                'mapValue' => [
                    'fields' => $existingNotes,
                ],
            ];

            // Hitung periodLength jika start_date ada
            $startDateStr = $existingDoc['fields']['start_date']['timestampValue'] ?? null;
            if ($startDateStr) {
                $startDate = \Carbon\Carbon::parse($startDateStr);
                $periodLength = $startDate->diffInDays($carbonDate) + 1;

                Log::info("Calculated period length in 'end' event: $periodLength");

                // Simpan periodLength juga di dokumen active
                $fields['periodLength'] = ['integerValue' => $periodLength];
            }
        } elseif ($eventType === 'noteOnly' && $note) {
            $existingDoc = Http::withToken($this->accessToken)->get($docUrl)->json();
            $existingNotes = $existingDoc['fields']['notes']['mapValue']['fields'] ?? [];
            $existingNotes[$normalizedDate] = ['stringValue' => $note];


            $fields['notes'] = [
                'mapValue' => [
                    'fields' => $existingNotes,
                ],
            ];
        } else {
            Log::error("Invalid event type: $eventType");
            return response()->json(['error' => 'Invalid event type'], 400);
        }

        $updateMaskQuery = implode('&', array_map(fn($field) => 'updateMask.fieldPaths=' . $field, array_keys($fields)));
        $patchUrl = $docUrl . '?' . $updateMaskQuery;

        Log::info("Sending PATCH request to: $patchUrl", $fields);

        $response = Http::withToken($this->accessToken)
            ->patch($patchUrl, ['fields' => $fields]);

        Log::info("Firestore response", $response->json());

        if ($response->failed()) {
            Log::error('Failed to update period document', ['response' => $response->body()]);
            return response()->json([
                'error' => 'Failed to update period document',
                'details' => $response->body(),
            ], 500);
        }

        if ($eventType === 'end') {
            $activeDoc = Http::withToken($this->accessToken)->get($docUrl)->json();
            Log::info("Fetched active period doc", $activeDoc);

            $startDateStr = $activeDoc['fields']['start_date']['timestampValue'] ?? null;

            if ($startDateStr) {
                $startDate = \Carbon\Carbon::parse($startDateStr);
                $periodLength = $startDate->diffInDays($carbonDate) + 1;

                Log::info("Calculated period length: $periodLength");

                $userUpdate = [
                    'lastPeriodStartDate' => ['timestampValue' => $startDate->toIso8601String()],
                    'lastPeriodEndDate' => ['timestampValue' => $carbonDate->toIso8601String()],
                    'periodLength' => ['integerValue' => $periodLength],
                ];

                $updateFields = array_keys($userUpdate); // ['lastPeriodStartDate', 'lastPeriodEndDate', 'periodLength']
                $updateMask = implode('&', array_map(fn($field) => 'updateMask.fieldPaths=' . $field, $updateFields));
                $userUpdateUrl = $userDocUrl . '?' . $updateMask;

                Http::withToken($this->accessToken)->patch($userUpdateUrl, [
                    'fields' => $userUpdate,
                ]);


                $userDoc = Http::withToken($this->accessToken)->get($userDocUrl)->json();
                $cycleLength = (int) $userDoc['fields']['cycleLength']['integerValue'];


                Log::info("Cycle length from user doc: $cycleLength");

                $predictedStart = $startDate->copy()->addDays($cycleLength);
                $periodLength = (int) $periodLength; // ensure integer
                $predictedEnd = $predictedStart->copy()->addDays($periodLength - 1);


                $predYear = $predictedStart->format('Y');
                $predMonth = $predictedStart->format('m');

                $predictionDocUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}/predictions/{$predYear}/{$predMonth}/active";

                Log::info("Posting predicted dates to: $predictionDocUrl", [
                    'predicted_start' => $predictedStart->toIso8601String(),
                    'predicted_end' => $predictedEnd->toIso8601String(),
                ]);

                Http::withToken($this->accessToken)->patch($predictionDocUrl, [
                    'fields' => [
                        'predicted_start' => ['timestampValue' => $predictedStart->toIso8601String()],
                        'predicted_end' => ['timestampValue' => $predictedEnd->toIso8601String()],
                        'created_at' => ['timestampValue' => now()->toIso8601String()],
                        'is_confirmed' => ['booleanValue' => false],
                    ],
                ]);
            }
        }

        Log::info('Event added successfully');
        return response()->json(['message' => 'Event added successfully']);
    }

    /**
     * Update cycle length based on previous or next month.
     */
    protected function updateCycleLength(string $uid, \Carbon\Carbon $currentStartDate)
    {
        $projectId = config('firebase.project_id');
        $userRefBase = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}/periods";

        $cycleLength = 27; // fallback

        // Cek bulan sebelumnya
        $prevMonth = $currentStartDate->copy()->subMonth();
        $prevYearStr = $prevMonth->format('Y');
        $prevMonthStr = $prevMonth->format('m');
        $prevDocUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}/periods/{$prevYearStr}/{$prevMonthStr}/active";

        $response = Http::withToken($this->accessToken)->get($prevDocUrl);
        $prevStartDate = null;

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['fields']['start_date']['timestampValue'])) {
                $prevStartDate = \Carbon\Carbon::parse($data['fields']['start_date']['timestampValue']);
            }
        }

        Log::info('currentStartDate: ' . $currentStartDate->toDateTimeString());
        if ($prevStartDate) {
            Log::info('prevStartDate: ' . $prevStartDate->toDateTimeString());
            Log::info('diffInDays (current - prev): ' .    $cycleLength = $prevStartDate->diffInDays($currentStartDate));
        } else {
            Log::info('prevStartDate not found');

            // Cek bulan berikutnya
            $nextMonth = $currentStartDate->copy()->addMonth();
            $nextYearStr = $nextMonth->format('Y');
            $nextMonthStr = $nextMonth->format('m');
            $nextDocUrl = "{$userRefBase}/{$nextYearStr}/{$nextMonthStr}/active";

            $response = Http::withToken($this->accessToken)->get($nextDocUrl);
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['fields']['start_date']['timestampValue'])) {
                    $nextStartDate = \Carbon\Carbon::parse($data['fields']['start_date']['timestampValue']);
                    $cycleLength = $nextStartDate->diffInDays($currentStartDate);
                }
            }
        }

        return $cycleLength;
    }


    /**
     * Remove an event and its notes.
     */
    public function removeEvent(Request $request)
    {
        $uid = $request->get('firebase_uid');
        if (!$uid) {
            Log::warning('Unauthorized access attempt: missing user ID');
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }

        $date = $request->date;
        if (!$date) {
            Log::warning("Remove event called without date. UID: {$uid}");
            return response()->json(['error' => 'Missing date'], 400);
        }

        Log::info("Remove event requested. UID: {$uid}, Date: {$date}");

        $projectId = config('firebase.project_id');
        $carbonDate = \Carbon\Carbon::parse($date);
        $year = $carbonDate->format('Y');
        $month = $carbonDate->format('m');
        $normalizedDate = $carbonDate->format('Y-m-d');

        $docUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}/periods/{$year}/{$month}/active";

        $existingDoc = Http::withToken($this->accessToken)->get($docUrl);

        if ($existingDoc->failed()) {
            return response()->json(['error' => 'Failed to fetch document'], 500);
        }

        $docData = $existingDoc->json();

        $emptyNotes = (object)[];

        $startDate = $docData['fields']['start_date']['timestampValue'] ?? null;
        $endDate = $docData['fields']['end_date']['timestampValue'] ?? null;
        $fields = [
            'notes' => ['mapValue' => ['fields' => $emptyNotes]],
            'periodLength' => ['nullValue' => null],
        ];

        if ($startDate) {
            $startDateCarbon = \Carbon\Carbon::parse($startDate)->setTimezone('Asia/Jakarta');
            if ($startDateCarbon->format('Y-m-d') === $normalizedDate) {
                Log::info('Start date matches, resetting start_date and end_date to null');
                $fields['start_date'] = ['nullValue' => null];
                $fields['end_date'] = ['nullValue' => null];
            }
        }

        if ($endDate) {
            $endDateCarbon = \Carbon\Carbon::parse($endDate)->setTimezone('Asia/Jakarta');
            if ($endDateCarbon->format('Y-m-d') === $normalizedDate) {
                Log::info('End date matches, resetting end_date to null');
                $fields['end_date'] = ['nullValue' => null];
            }
        }

        $updateMaskParts = [];
        foreach (array_keys($fields) as $field) {
            $updateMaskParts[] = "updateMask.fieldPaths={$field}";
        }
        $updateMask = implode('&', $updateMaskParts);

        $patchUrl = $docUrl . '?' . $updateMask;
        $response = Http::withToken($this->accessToken)->patch($patchUrl, ['fields' => $fields]);

        if ($response->failed()) {
            return response()->json(['error' => 'Failed to update document', 'details' => $response->body()], 500);
        }
        return response()->json(['message' => 'Event and notes removed successfully']);
    }

    /**
     * Remove a note for a specific date.
     */
    public function removeNote(Request $request)
    {
        $uid = $request->get('firebase_uid');
        $date = $request->date;

        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }
        if (!$date) {
            return response()->json(['error' => 'Missing date'], 400);
        }

        $carbonDate = \Carbon\Carbon::parse($date);
        $year = $carbonDate->format('Y');
        $month = $carbonDate->format('m');
        $formattedDate = $carbonDate->format('Y-m-d');

        $projectId = config('firebase.project_id');
        $docUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/users/{$uid}/periods/{$year}/{$month}/active";

        // Ambil dokumen Firestore
        $existingDoc = Http::withToken($this->accessToken)->get($docUrl);
        if ($existingDoc->failed()) {
            return response()->json(['error' => 'Failed to fetch document'], 500);
        }
        $docData = $existingDoc->json();

        // Ambil notes map (object) dari dokumen
        $notes = $docData['fields']['notes']['mapValue']['fields'] ?? [];

        // Hapus key tanggal yg diinginkan dari notes
        if (array_key_exists($formattedDate, $notes)) {
            unset($notes[$formattedDate]);

            // Persiapkan data update
            $fields = [
                'notes' => ['mapValue' => ['fields' => $notes]]
            ];

            // Build updateMask
            $updateMaskParts = ['updateMask.fieldPaths=notes'];
            $updateMask = implode('&', $updateMaskParts);

            $patchUrl = $docUrl . '?' . $updateMask;

            // PATCH request update notes
            $response = Http::withToken($this->accessToken)->patch($patchUrl, ['fields' => $fields]);

            if ($response->failed()) {
                return response()->json(['error' => 'Failed to update notes', 'details' => $response->body()], 500);
            }
            return response()->json(['message' => 'Note removed successfully']);
        } else {
            return response()->json(['message' => 'Note not found for the given date']);
        }
    }
}
