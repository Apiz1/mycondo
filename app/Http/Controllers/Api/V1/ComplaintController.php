<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreComplaintRequest;
use App\Http\Resources\ComplaintResource;
use App\Models\ActivityLog;
use App\Models\Complaint;
use App\Notifications\ComplaintStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;


class ComplaintController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(ComplaintStatus::class)],
            'category' => ['nullable', Rule::enum(ComplaintCategory::class)],
        ]);

        $query = Complaint::with(['unit.unitType', 'reporter:id,name'])->latest();

        if ($request->user()->role === UserRole::Resident) {
            $query->whereIn('unit_id', $request->user()->activeUnitIds());
        }

        $query
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('category'), fn ($q, $category) => $q->where('category', $category));

        return ComplaintResource::collection($query->paginate(15));
    }

    public function store(StoreComplaintRequest $request)
    {
        $user = $request->user();
        $unitIds = $user->activeUnitIds();

        if ($unitIds->isEmpty()) {
            return response()->json(['message' => 'Only current residents can file complaints.'], 403);
        }

        $unitId = $request->validated('unit_id') ?? ($unitIds->count() === 1 ? $unitIds->first() : null);

        if (! $unitId) {
            throw ValidationException::withMessages(['unit_id' => ['Choose which unit this complaint is about.']]);
        }

        $complaint = Complaint::create([
            'unit_id' => $unitId,
            'user_id' => $user->id,
            'category' => $request->validated('category'),
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'status' => ComplaintStatus::Open,
            'photo_path' => $request->file('photo')?->store('complaint-photos'),
        ]);

        ActivityLog::record('complaint.created', "Complaint filed: {$complaint->title}", $complaint);

        return new ComplaintResource($complaint->load(['unit.unitType', 'reporter:id,name']));
    }

    public function show(Complaint $complaint)
    {
        Gate::authorize('view', $complaint);

        return new ComplaintResource(
            $complaint->load(['unit.unitType', 'reporter:id,name', 'comments.author:id,name,role'])
        );
    }

    public function photo(Complaint $complaint)
    {
        Gate::authorize('view', $complaint);

        abort_unless($complaint->photo_path && Storage::exists($complaint->photo_path), 404);

        return Storage::response($complaint->photo_path);
    }

    public function updateStatus(Request $request, Complaint $complaint)
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ComplaintStatus::class)],
        ]);

        $new = ComplaintStatus::from($data['status']);

        return DB::transaction(function () use ($complaint, $new) {
            $complaint = Complaint::whereKey($complaint->id)->lockForUpdate()->first();

            if ($complaint->status === ComplaintStatus::Closed) {
                return response()->json(['message' => 'A closed complaint cannot be changed.'], 409);
            }

            $old = $complaint->status;

            $complaint->update([
                'status' => $new,
                'resolved_at' => in_array($new, [ComplaintStatus::Resolved, ComplaintStatus::Closed], true)
                    ? ($complaint->resolved_at ?? now())
                    : null,
            ]);

            ActivityLog::record(
                'complaint.status_changed',
                "Complaint #{$complaint->id} set to {$new->value}",
                $complaint,
                ['status' => $new->value],
            );

             // Only notify when the status really changed
            if ($old !== $new) {
                $complaint->reporter->notify(new ComplaintStatusChanged($complaint));
            }

            return new ComplaintResource($complaint->load(['unit.unitType', 'reporter:id,name']));
        });
    }
}
