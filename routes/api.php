<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\ResidencyController;
use App\Http\Controllers\Api\BillplzWebhookController;
use App\Http\Controllers\Api\V1\BillplzPaymentController;
use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\FacilityController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\ComplaintController;
use App\Http\Controllers\Api\V1\ComplaintCommentController;
use App\Http\Controllers\Api\V1\VisitorController;
use App\Http\Controllers\Api\V1\GateController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\UserController;

    Route::post('/webhooks/billplz', BillplzWebhookController::class)
        ->middleware('throttle:60,1')
        ->name('billplz.callback');


Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', fn (Request $request) => $request->user());
        Route::get('/committee-only', fn () => ['ok' => true])->middleware('role:committee');
        Route::apiResource('units', UnitController::class)->middleware('role:committee');
        Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('role:resident,committee');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('role:resident,committee');
        Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->middleware('role:resident');
        Route::get('/announcements', [AnnouncementController::class, 'index']);
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show']);
        Route::post('/invoices/{invoice}/pay/billplz', [BillplzPaymentController::class, 'store'])->middleware('role:resident');
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->middleware('role:admin');
        Route::get('/bookings', [BookingController::class, 'index'])->middleware('role:resident,committee');
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->middleware('role:resident,committee');

        // any logged-in role
        Route::get('/facilities', [FacilityController::class, 'index']);
        Route::get('/facilities/{facility}/availability', [FacilityController::class, 'availability']);

        // residents
        Route::post('/facilities/{facility}/bookings', [BookingController::class, 'store'])->middleware('role:resident');

        // Complaints
        Route::get('/complaints', [ComplaintController::class, 'index'])->middleware('role:resident,committee');
        Route::post('/complaints', [ComplaintController::class, 'store'])->middleware('role:resident');
        Route::get('/complaints/{complaint}', [ComplaintController::class, 'show'])->middleware('role:resident,committee');
        Route::get('/complaints/{complaint}/photo', [ComplaintController::class, 'photo'])->middleware('role:resident,committee');
        Route::post('/complaints/{complaint}/comments', [ComplaintCommentController::class, 'store'])->middleware('role:resident,committee');

        // Visitor
        Route::post('/visitors', [VisitorController::class, 'store'])->middleware('role:resident');
        Route::get('/visitors', [VisitorController::class, 'index'])->middleware('role:resident,committee');
        Route::post('/visitors/{visitor}/cancel', [VisitorController::class, 'cancel'])->middleware('role:resident,committee');

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);

        Route::middleware('role:committee')->group(function () {
            Route::get('/payments', [PaymentController::class, 'index']);
            Route::get('/payments/{payment}/proof', [PaymentController::class, 'proof']);
            Route::post('/payments/{payment}/approve', [PaymentController::class, 'approve']);
            Route::post('/payments/{payment}/reject', [PaymentController::class, 'reject']);
            Route::post('/announcements', [AnnouncementController::class, 'store']);
            Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update']);
            Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
            Route::get('/units/{unit}/residents', [ResidencyController::class, 'index']);
            Route::post('/units/{unit}/residents', [ResidencyController::class, 'store']);
            Route::patch('/residencies/{residency}/move-out', [ResidencyController::class, 'moveOut']);
            Route::post('/facilities', [FacilityController::class, 'store']);
            Route::put('/facilities/{facility}', [FacilityController::class, 'update']);
            Route::post('/bookings/{booking}/approve', [BookingController::class, 'approve']);
            Route::post('/bookings/{booking}/reject', [BookingController::class, 'reject']);
            Route::patch('/complaints/{complaint}/status', [ComplaintController::class, 'updateStatus']);
        });

        Route::middleware(['role:security', 'throttle:30,1'])->prefix('gate')->group(function () {
            Route::get('/expected', [GateController::class, 'expected']);
            Route::post('/check-in', [GateController::class, 'checkIn']);
            Route::post('/check-out', [GateController::class, 'checkOut']);
        });

        Route::middleware('role:admin')->prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::patch('/{user}/deactivate', [UserController::class, 'deactivate']);
            Route::patch('/{user}/activate', [UserController::class, 'activate']);
            Route::post('/{user}/reset-password', [UserController::class, 'resetPassword']);
        });

    });
});
