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
                            ? \Carbon\Carbon::parse($fields['start_date']['timestampValue'])->toDateString()
                            : null;
                        $endDate = isset($fields['end_date']['timestampValue'])
                            ? \Carbon\Carbon::parse($fields['end_date']['timestampValue'])->toDateString()
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
            ? \Carbon\Carbon::parse($fields['predicted_start']['timestampValue'])->toDateString()
            : null;
        $predictedEndDate = isset($fields['predicted_end']['timestampValue'])
            ? \Carbon\Carbon::parse($fields['predicted_end']['timestampValue'])->toDateString()
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
     * Add an event to the user's calendar.
     */
    public function addEvent(Request $request)
    {
        $uid = $request->get('firebase_uid');
        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }

        $request->validate([
            'event_date' => 'required|date',
            'event_type' => 'required|string',
            'note' => 'nullable|string',
        ]);

        $eventDate = $request->input('event_date');
        $eventType = $request->input('event_type');
        $note = $request->input('note');

        $year = date('Y', strtotime($eventDate));
        $month = date('m', strtotime($eventDate));
        $docRef = $this->firestore->collection('users')->document($this->$uid)
            ->collection('periods')->document($year)->collection($month)->document('active');

        $doc = $docRef->snapshot();

        if (!$doc->exists()) {
            $docRef->set([
                'notes' => [],
                'start_date' => null,
                'end_date' => null,
                'periodLength' => 0,
            ]);
        }

        $data = $doc->data();
        $notes = $data['notes'] ?? [];

        if ($eventType === 'start') {
            $docRef->set([
                'start_date' => $eventDate,
                'notes' => array_merge($notes, [$eventDate => $note]),
            ], ['merge' => true]);
        } elseif ($eventType === 'end') {
            $docRef->set([
                'end_date' => $eventDate,
                'notes' => array_merge($notes, [$eventDate => $note]),
            ], ['merge' => true]);
        } elseif ($eventType === 'noteOnly') {
            $notes[date('Y-m-d', strtotime($eventDate))] = $note;
            $docRef->set(['notes' => $notes], ['merge' => true]);
        }

        return response()->json(['message' => 'Event added successfully.']);
    }

    /**
     * Remove an event and its notes.
     */
    public function removeEventAndNotes(Request $request)
    {
        $uid = $request->get('firebase_uid');
        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }
        $request->validate([
            'event_date' => 'required|date',
        ]);

        $eventDate = $request->input('event_date');
        $year = date('Y', strtotime($eventDate));
        $month = date('m', strtotime($eventDate));
        $docRef = $this->firestore->collection('users')->document($this->$uid)
            ->collection('periods')->document($year)->collection($month)->document('active');

        $doc = $docRef->snapshot();

        if ($doc->exists()) {
            $data = $doc->data();
            $notes = $data['notes'] ?? [];
            unset($notes[date('Y-m-d', strtotime($eventDate))]);

            $docRef->set(['notes' => $notes], ['merge' => true]);
            return response()->json(['message' => 'Event and notes removed successfully.']);
        }

        return response()->json(['message' => 'Document does not exist.'], 404);
    }

    /**
     * Mark the start and end of a period.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function markStartEndPeriod(Request $request)
    {

        $uid = $request->get('firebase_uid');
        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }

        $request->validate([
            'start_date' => 'required|date',
            'note' => 'nullable|string',
        ]);

        $startDate = $request->input('start_date');
        $note = $request->input('note', '');
        $endDate = date('Y-m-d', strtotime($startDate . ' + 4 days'));

        // Fetch user data from Firestore
        $userDoc = $this->firestore->collection('users')->document($this->$uid)->snapshot();

        if (!$userDoc->exists()) {
            return response()->json(['message' => 'User  data not found.'], 404);
        }

        $data = $userDoc->data();
        $periodLength = $data['periodLength'] ?? 5;

        // Add start and end events
        $this->addEventToFirestore($startDate, 'start', $note);
        $this->addEventToFirestore($endDate, 'end');

        // Save to Firestore for the active period
        $year = date('Y', strtotime($startDate));
        $month = date('m', strtotime($startDate));

        // Fetch existing notes
        $existingNotesSnap = $this->firestore->collection('users')->document($this->$uid)
            ->collection('periods')->document($year)->collection($month)->document('active')->snapshot();

        $existingNotes = [];
        if ($existingNotesSnap->exists()) {
            $existingNotes = $existingNotesSnap->data()['notes'] ?? [];
        }

        // Filter notes between start_date and end_date
        $filteredNotes = [];
        foreach ($existingNotes as $date => $noteValue) {
            $noteDate = date('Y-m-d', strtotime($date));
            if (
                $noteDate > date('Y-m-d', strtotime($startDate . ' -1 day')) &&
                $noteDate < date('Y-m-d', strtotime($endDate . ' +1 day'))
            ) {
                $filteredNotes[$date] = $noteValue;
            }
        }

        // Add new note if provided
        if (!empty($note)) {
            $filteredNotes[date('Y-m-d', strtotime($startDate))] = $note;
        }

        // Update Firestore with start_date, end_date, and notes
        $this->firestore->collection('users')->document($this->$uid)
            ->collection('periods')->document($year)->collection($month)->document('active')
            ->set([
                'start_date' => $startDate,
                'end_date' => $endDate,
                'notes' => $filteredNotes,
            ], ['merge' => true]);

        // Update user data for cycle length and last period dates
        $this->updateUserPeriodData($startDate, $endDate, $periodLength);

        return response()->json(['message' => 'Period marked successfully.']);
    }

    /**
     * Add an event to Firestore.
     *
     * @param string $eventDate
     * @param string $eventType
     * @param string|null $note
     */
    private function addEventToFirestore($eventDate, $eventType, $note = null)
    {
        $uid = request()->get('firebase_uid');
        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }

        $year = date('Y', strtotime($eventDate));
        $month = date('m', strtotime($eventDate));
        $docRef = $this->firestore->collection('users')->document($this->$uid)
            ->collection('periods')->document($year)->collection($month)->document('active');

        $doc = $docRef->snapshot();

        if (!$doc->exists()) {
            $docRef->set([
                'notes' => [],
                'start_date' => null,
                'end_date' => null,
                'periodLength' => 0,
            ]);
        }

        $notes = $doc->data()['notes'] ?? [];
        if ($eventType === 'start') {
            $docRef->set([
                'start_date' => $eventDate,
                'notes' => array_merge($notes, [$eventDate => $note]),
            ], ['merge' => true]);
        } elseif ($eventType === 'end') {
            $docRef->set([
                'end_date' => $eventDate,
                'notes' => array_merge($notes, [$eventDate => $note]),
            ], ['merge' => true]);
        }
    }

    /**
     * Update user period data in Firestore.
     *
     * @param string $startDate
     * @param string $endDate
     * @param int $periodLength
     */
    private function updateUserPeriodData($startDate, $endDate, $periodLength)
    {

        $uid = request()->get('firebase_uid');
        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }
        $userRef = $this->firestore->collection('users')->document($this->$uid);
        $userDoc = $userRef->snapshot();

        if ($userDoc->exists()) {
            $lastStartDate = $userDoc->data()['lastPeriodStartDate'] ?? null;
            $lastEndDate = $userDoc->data()['lastPeriodEndDate'] ?? null;

            $userRef->update([
                'lastPeriodStartDate' => $startDate,
                'lastPeriodEndDate' => $endDate,
                'cycleLength' => $periodLength,
            ]);
        }
    }

    /**
     * Fetch user data.
     */
    public function fetchUserData()
    {
        $uid = request()->get('firebase_uid');
        if (!$uid) {
            return response()->json(['error' => 'Unauthorized: missing user ID'], 401);
        }
        $userDoc = $this->firestore->collection('users')->document($this->$uid)->snapshot();

        if ($userDoc->exists()) {
            return response()->json($userDoc->data());
        }

        return response()->json(['message' => 'User  data not found.'], 404);
    }
}
