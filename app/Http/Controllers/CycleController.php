<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CycleController extends Controller
{
    public function getStatus(Request $request)
    {
        $uid = $request->firebase_uid;
        $user = User::where('firebase_uid', $uid)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $startDate = $user->last_period_start_date;
        $endDate = $user->last_period_end_date;
        $cycleLength = $user->cycle_length ?? 28;

        if (!$startDate) {
            return response()->json([
                'message' => 'No cycle data available.',
                'status' => 'Please log your last period date.',
            ]);
        }

        $start = Carbon::parse($startDate);
        $end = $endDate ? Carbon::parse($endDate) : null;
        $today = Carbon::now();
        $predictedStart = $start->copy()->addDays($cycleLength);

        if ($today->gt($predictedStart) && $start->month != $today->month) {
            $daysDelayed = $today->diffInDays($start);
            return response()->json([
                'message' => 'Your period is delayed!',
                'status' => "Delayed by $daysDelayed days since last month.",
            ]);
        } elseif ($today->between($start, $end)) {
            $day = $today->diffInDays($start) + 2;
            return response()->json([
                'message' => 'Your period has started!',
                'status' => "Day $day",
            ]);
        } elseif ($today->lt($predictedStart)) {
            $daysLeft = $predictedStart->diffInDays($today) + 1;
            return response()->json([
                'message' => "Upcoming period in $daysLeft days 🌟",
                'status' => "Your next period is expected to start on, " . $predictedStart->format('F j Y'),
            ]);
        } else {
            return response()->json([
                'message' => 'Your period is finished.',
                'status' => 'End of cycle.',
            ]);
        }
    }
}
