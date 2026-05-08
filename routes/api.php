<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassroomController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ClassroomInterestsController;
use App\Http\Controllers\MakeupSessionController;
use App\Http\Controllers\MakeupSuggestionController;
use App\Http\Controllers\MazeretController;
use App\Http\Controllers\MazeretFileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StudentInterestsController;
use App\Http\Controllers\StudentAchievementsController;
use App\Http\Controllers\StudentAuthController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentInboxController;
use App\Http\Controllers\StudentMazeretController;
use App\Http\Controllers\StudentMessagesController;
use App\Http\Controllers\StudentProfileController;
use Illuminate\Support\Facades\Route;

// Sağlık kontrolü — Electron portable build başlatma sırasında waitOn için kullanılır.
Route::get('/health', fn () => response()->json(['ok' => true, 'ts' => now()->toIso8601String()]));

// Herkese açık
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Öğrenci paneli — herkese açık login + şifre sıfırlama
Route::post('/student/login', [StudentAuthController::class, 'login']);
Route::post('/student/forgot-password', [StudentAuthController::class, 'forgotPassword']);
Route::post('/student/reset-password', [StudentAuthController::class, 'resetPassword']);

// Öğrenci paneli — token korumalı (Sanctum 'student' guard)
Route::middleware('auth:student')->prefix('student')->group(function () {
    Route::get('/me', [StudentAuthController::class, 'me']);
    Route::post('/change-password', [StudentAuthController::class, 'changePassword']);
    Route::post('/logout', [StudentAuthController::class, 'logout']);

    Route::get('/dashboard', [StudentDashboardController::class, 'dashboard']);
    Route::get('/attendances', [StudentDashboardController::class, 'attendances']);
    Route::get('/schedule', [StudentDashboardController::class, 'schedule']);
    Route::get('/holidays', [StudentDashboardController::class, 'holidays']);
    Route::get('/achievements', [StudentAchievementsController::class, 'index']);
    Route::put('/achievements/showcase', [StudentAchievementsController::class, 'updateShowcase']);
    Route::get('/interests', [StudentInterestsController::class, 'index']);
    Route::put('/interests', [StudentInterestsController::class, 'update']);

    // Öğrenci mesajları (duyuru-kaynaklı)
    Route::get('/messages', [StudentMessagesController::class, 'index']);

    Route::put('/profile', [StudentProfileController::class, 'update']);

    // Öğrenci inbox'ı
    Route::get('/inbox', [StudentInboxController::class, 'index']);
    Route::get('/inbox/unread-count', [StudentInboxController::class, 'unreadCount']);
    Route::post('/inbox/read-all', [StudentInboxController::class, 'markAllRead']);
    Route::post('/inbox/{message}/read', [StudentInboxController::class, 'markRead']);
    Route::delete('/inbox/{message}', [StudentInboxController::class, 'destroy']);
    Route::delete('/inbox', [StudentInboxController::class, 'clearAll']);

    // Öğrenci mazeret yönetimi
    Route::get('/mazerets', [StudentMazeretController::class, 'index']);
    Route::post('/mazerets', [StudentMazeretController::class, 'store']);
    Route::get('/mazerets/{mazeret}', [StudentMazeretController::class, 'show']);
    Route::delete('/mazerets/{mazeret}', [StudentMazeretController::class, 'destroy']);
    Route::get('/mazerets/{mazeret}/file', [MazeretFileController::class, 'downloadAsStudent']);
});

// Sanctum ile korunan rotalar
Route::middleware('auth:sanctum')->group(function () {
    // --- Auth & profil ---
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/me', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::put('/preferences', [PreferencesController::class, 'update']);

    Route::put('/password', [PasswordController::class, 'update']);

    // --- Faz 1: Sınıflar ---
    Route::apiResource('classrooms', ClassroomController::class);
    Route::post('classrooms/{classroom}/archive', [ClassroomController::class, 'archive']);
    Route::post('classrooms/{classroom}/restore', [ClassroomController::class, 'restore']);

    // --- Faz 1: Öğrenciler (sınıfa gömülü + bağımsız update/delete) ---
    Route::get('/classrooms/{classroom}/students', [StudentController::class, 'index']);
    Route::post('/classrooms/{classroom}/students', [StudentController::class, 'store']);
    Route::post('/classrooms/{classroom}/students/bulk', [StudentController::class, 'bulkStore']);
    Route::put('/students/{student}', [StudentController::class, 'update']);
    Route::delete('/students/{student}', [StudentController::class, 'destroy']);

    // --- Faz 2: Yoklama ve raporlar ---
    Route::post('/classrooms/{classroom}/attendances', [AttendanceController::class, 'store']);
    Route::get('/classrooms/{classroom}/attendances/{date}', [AttendanceController::class, 'day']);
    Route::get('/classrooms/{classroom}/stats', [AttendanceController::class, 'classStats']);
    Route::get('/classrooms/{classroom}/interests', [ClassroomInterestsController::class, 'show']);
    Route::get('/classrooms/{classroom}/makeup-suggestions', [MakeupSuggestionController::class, 'show']);

    Route::get('/makeup-sessions', [MakeupSessionController::class, 'teacherIndex']);
    Route::get('/classrooms/{classroom}/makeup-sessions', [MakeupSessionController::class, 'index']);
    Route::post('/classrooms/{classroom}/makeup-sessions', [MakeupSessionController::class, 'store']);
    Route::put('/makeup-sessions/{session}', [MakeupSessionController::class, 'update']);
    Route::delete('/makeup-sessions/{session}', [MakeupSessionController::class, 'destroy']);

    // Duyurular (hoca tarafı)
    Route::post('/classrooms/{classroom}/announcements', [AnnouncementController::class, 'store']);
    Route::post('/classrooms/{classroom}/announcements/preview', [AnnouncementController::class, 'preview']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);

    Route::get('/attendances', [AttendanceController::class, 'history']);
    Route::get('/risk-students', [AttendanceController::class, 'riskStudents']);
    Route::get('/dashboard', [AttendanceController::class, 'dashboardStats']);

    // --- Faz 3: Öğrenci bildirimleri (EmailJS yerine) ---
    Route::post('/notify-student', [NotificationController::class, 'notifyStudent']);

    // --- Bildirimler (sistem ici inbox) ---
    Route::get('/inbox', [InboxController::class, 'index']);
    Route::get('/inbox/unread-count', [InboxController::class, 'unreadCount']);
    Route::post('/inbox/read-all', [InboxController::class, 'markAllRead']);
    Route::post('/inbox/{message}/read', [InboxController::class, 'markRead']);
    Route::delete('/inbox/{message}', [InboxController::class, 'destroy']);
    Route::delete('/inbox', [InboxController::class, 'clearAll']);

    // --- Rapor & Dışa Aktar ---
    Route::get('/reports/options', [ReportController::class, 'options']);
    Route::get('/reports/data', [ReportController::class, 'data']);

    // --- Mazeret Yönetimi (hoca tarafı) ---
    Route::get('/mazerets', [MazeretController::class, 'index']);
    Route::get('/mazerets/pending-count', [MazeretController::class, 'pendingCount']);
    Route::post('/mazerets/{mazeret}/approve', [MazeretController::class, 'approve']);
    Route::post('/mazerets/{mazeret}/reject', [MazeretController::class, 'reject']);
    Route::get('/mazerets/{mazeret}/file', [MazeretFileController::class, 'downloadAsTeacher']);

    // --- Akademik Takvim ---
    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::post('/holidays', [HolidayController::class, 'store']);
    Route::post('/holidays/import-public', [HolidayController::class, 'importPublic']);
    Route::post('/holidays/bulk-range', [HolidayController::class, 'bulkRange']);
    Route::post('/holidays/copy-year', [HolidayController::class, 'copyYear']);
    Route::put('/holidays/{holiday}', [HolidayController::class, 'update']);
    Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);
});
