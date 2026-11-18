<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Facility;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function store(StoreBookingRequest $request, Facility $facility)
    {
        $user = $request->user();

        if ($user->activeUnitIds()->isEmpty()) {
            return response()->json(['message' => 'Only current residents can book facilities.'], 403);
        }

        if (! $facility->is_active) {
            return response()->json(['message' => 'This facility is not available for booking.'], 409);
        }

        $data = $request->validated();
        $start = Carbon::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['start_time']}");
        $end = Carbon::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['end_time']}");
        $opens = substr($facility->open_time, 0, 5);
        $closes = substr($facility->close_time, 0, 5);

        $errors = [];

        if ($start->isPast()) {
            $errors['start_time'][] = 'The booking must start in the future.';
        }

        if ($data['start_time'] < $opens || $data['end_time'] > $closes) {
            $errors['start_time'][] = "This facility is open from {$opens} to {$closes}.";
        }

        if ($start->diffInMinutes($end) > $facility->max_duration_minutes) {
            $errors['end_time'][] = "The longest booking is {$facility->max_duration_minutes} minutes.";
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $booking = DB::transaction(fn () => Booking::create([
                'facility_id' => $facility->id,
                'user_id' => $user->id,
                'start_at' => $start,
                'end_at' => $end,
                'status' => $facility->needs_approval ? BookingStatus::Pending : BookingStatus::Approved,
                'purpose' => $data['purpose'] ?? null,
            ]));
        } catch (QueryException $e) {
            // 23P01 = exclusion_violation: the database refused an overlapping booking
            if (($e->errorInfo[0] ?? null) === '23P01') {
                return response()->json(['message' => 'That time slot is no longer available.'], 409);
            }

            throw $e;
        }

        ActivityLog::record(
            'booking.created',
            "Booked {$facility->name} on {$data['date']}, {$data['start_time']} to {$data['end_time']}",
            $booking,
        );

        return new BookingResource($booking->load('facility'));
    }
}