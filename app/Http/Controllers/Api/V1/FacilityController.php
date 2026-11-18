<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\FacilityRequest;
use App\Http\Resources\FacilityResource;
use App\Models\ActivityLog;
use App\Models\Facility;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FacilityController extends Controller
{
    public function index(Request $request)
    {
        $isStaff = in_array($request->user()->role, [UserRole::Admin, UserRole::Committee], true);

        $facilities = Facility::query()
            ->when(! $isStaff, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return FacilityResource::collection($facilities);
    }

    public function store(FacilityRequest $request)
    {
        $facility = Facility::create($request->validated())->refresh();

        ActivityLog::record('facility.created', "Facility created: {$facility->name}", $facility);

        return new FacilityResource($facility);
    }

    public function update(FacilityRequest $request, Facility $facility)
    {
        $facility->update($request->validated());

        ActivityLog::record('facility.updated', "Facility updated: {$facility->name}", $facility);

        return new FacilityResource($facility->refresh());
    }

    public function availability(Request $request, Facility $facility)
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        $day = Carbon::createFromFormat('!Y-m-d', $data['date']);

        $booked = $facility->bookings()
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Approved->value])
            ->where('start_at', '<', $day->copy()->addDay())
            ->where('end_at', '>', $day)
            ->orderBy('start_at')
            ->get()
            ->map(fn ($booking) => [
                'start_at' => $booking->start_at->format('H:i'),
                'end_at' => $booking->end_at->format('H:i'),
                'status' => $booking->status->value,
            ]);

        return response()->json([
            'facility_id' => $facility->id,
            'date' => $data['date'],
            'open_time' => substr($facility->open_time, 0, 5),
            'close_time' => substr($facility->close_time, 0, 5),
            'booked' => $booked,
        ]);
    }
}