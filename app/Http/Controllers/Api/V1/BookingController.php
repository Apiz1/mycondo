<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Facility;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;


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

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'facility_id' => ['nullable', 'integer'],
        ]);

        $query = Booking::with(['facility', 'user:id,name'])->latest('start_at');

        if ($request->user()->role === UserRole::Resident) {
            $query->where('user_id', $request->user()->id);
        }

        $query
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('facility_id'), fn ($q, $id) => $q->where('facility_id', $id));

        return BookingResource::collection($query->paginate(15));
    }

    public function cancel(Request $request, Booking $booking)
    {
        Gate::authorize('cancel', $booking);

        return DB::transaction(function () use ($booking) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Approved], true)) {
                return response()->json(['message' => 'This booking can no longer be cancelled.'], 409);
            }

            if ($booking->start_at->isPast()) {
                return response()->json(['message' => 'A booking that has already started cannot be cancelled.'], 409);
            }

            $booking->update(['status' => BookingStatus::Cancelled]);

            ActivityLog::record('booking.cancelled', "Booking #{$booking->id} cancelled", $booking);

            return new BookingResource($booking->load('facility'));
        });
    }

    public function approve(Request $request, Booking $booking)
    {
        return DB::transaction(function () use ($request, $booking) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if ($booking->status !== BookingStatus::Pending) {
                return response()->json(['message' => 'Only pending bookings can be approved.'], 409);
            }

            if ($booking->start_at->isPast()) {
                return response()->json(['message' => 'This booking has already started and can no longer be approved.'], 409);
            }

            $booking->update([
                'status' => BookingStatus::Approved,
                'decided_by' => $request->user()->id,
            ]);

            ActivityLog::record('booking.approved', "Booking #{$booking->id} approved", $booking);

            return new BookingResource($booking->load(['facility', 'user:id,name']));
        });
    }

    public function reject(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'review_note' => ['required', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $booking, $data) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if ($booking->status !== BookingStatus::Pending) {
                return response()->json(['message' => 'Only pending bookings can be rejected.'], 409);
            }

            $booking->update([
                'status' => BookingStatus::Rejected,
                'decided_by' => $request->user()->id,
                'review_note' => $data['review_note'],
            ]);

            ActivityLog::record('booking.rejected', "Booking #{$booking->id} rejected", $booking, ['reason' => $data['review_note']]);

            return new BookingResource($booking->load(['facility', 'user:id,name']));
        });
    }

}