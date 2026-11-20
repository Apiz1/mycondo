<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Http\Requests\StoreVisitorRequest;
use App\Http\Resources\VisitorResource;
use App\Models\ActivityLog;
use App\Models\Unit;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisitorController extends Controller
{
    private const DAILY_LIMIT = 10;

    public function store(StoreVisitorRequest $request)
    {
        $user = $request->user();
        $unitIds = $user->activeUnitIds();

        if ($unitIds->isEmpty()) {
            return response()->json(['message' => 'Only current residents can register visitors.'], 403);
        }

        $unitId = $request->validated('unit_id') ?? ($unitIds->count() === 1 ? $unitIds->first() : null);

        if (! $unitId) {
            throw ValidationException::withMessages(['unit_id' => ['Choose which unit this visitor is for.']]);
        }

        $visitor = DB::transaction(function () use ($request, $user, $unitId) {
            // Lock the unit row so two simultaneous requests cannot both slip under the daily limit
            Unit::whereKey($unitId)->lockForUpdate()->first();

            $issued = Visitor::where('unit_id', $unitId)
                ->whereDate('visit_date', $request->validated('visit_date'))
                ->where('status', '!=', VisitorStatus::Cancelled->value)
                ->count();

            if ($issued >= self::DAILY_LIMIT) {
                throw ValidationException::withMessages([
                    'visit_date' => ['This unit already has '.self::DAILY_LIMIT.' visitor passes for that date.'],
                ]);
            }

            return Visitor::create([
                'unit_id' => $unitId,
                'user_id' => $user->id,
                'name' => $request->validated('name'),
                'vehicle_plate' => $request->validated('vehicle_plate')
                    ? Str::upper($request->validated('vehicle_plate'))
                    : null,
                'visit_date' => $request->validated('visit_date'),
                'code' => Visitor::generateCode(),
                'status' => VisitorStatus::Pending,
            ]);
        });

        ActivityLog::record(
            'visitor.registered',
            "Visitor pass issued for {$visitor->name}",
            $visitor,
            ['visit_date' => $visitor->visit_date->toDateString()],
        );

        return new VisitorResource($visitor->load('unit'));
    }

    public function index(Request $request)
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'unit_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(VisitorStatus::class)],
        ]);

        $query = Visitor::with('unit')->orderByDesc('visit_date')->orderByDesc('id');

        if ($request->user()->role === UserRole::Resident) {
            $query->whereIn('unit_id', $request->user()->activeUnitIds());
        }

        $query
            ->when($request->query('date'), fn ($q, $date) => $q->whereDate('visit_date', $date))
            ->when($request->query('unit_id'), fn ($q, $id) => $q->where('unit_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));

        return VisitorResource::collection($query->paginate(20));
    }

    public function cancel(Visitor $visitor)
    {
        Gate::authorize('cancel', $visitor);

        return DB::transaction(function () use ($visitor) {
            $visitor = Visitor::whereKey($visitor->id)->lockForUpdate()->first();

            if ($visitor->status !== VisitorStatus::Pending) {
                return response()->json(['message' => 'Only passes that have not been used can be cancelled.'], 409);
            }

            $visitor->update(['status' => VisitorStatus::Cancelled]);

            ActivityLog::record('visitor.cancelled', "Visitor pass cancelled for {$visitor->name}", $visitor);

            return new VisitorResource($visitor->load('unit'));
        });
    }
}
