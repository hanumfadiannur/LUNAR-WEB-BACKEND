<?php


use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\FirebaseAuthMiddleware;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(FirebaseAuthMiddleware::class)->post('/cycle', [AuthController::class, 'saveCycleData']);
Route::middleware(FirebaseAuthMiddleware::class)->get('/user/cycle-status', [UserController::class, 'getCycleStatus']);
Route::middleware(FirebaseAuthMiddleware::class)->post('/user/update-fullname', [UserController::class, 'updateFullName']);
Route::middleware(FirebaseAuthMiddleware::class)->get('/user/periods-events', [CalendarController::class, 'fetchAllPeriodEvents']);
Route::middleware(FirebaseAuthMiddleware::class)->get('/user/period-prediction', [CalendarController::class, 'fetchNextPeriodPrediction']);
