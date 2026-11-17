<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Enums\UserRole;
use App\Http\Requests\StoreResidentRequest;
use App\Http\Resources\ResidencyResource;
use App\Models\Residency;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResidencyController extends Controller
{
   public function index(Request $request, Unit $unit)
    {
        $residencies = $unit->residencies()
            ->with('user')
            ->when(! $request->boolean('include_past'), fn ($q) => $q->whereNull('moved_out_at'))
            ->orderByDesc('id')
            ->get();

        return ResidencyResource::collection($residencies);
    }

    public function store(StoreResidentRequest $request, Unit $unit)
    {
        return DB::transaction(function () use ($request, $unit) {
            $user = User::where('email', $request->validated('email'))->first();

            if ($user && $user->role !== UserRole::Resident) {
                return response()->json(['message' => 'This email belongs to a non-resident account.'], 422);
            }

            $user ??= User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
                'password' => $request->validated('password'),
                'role' => UserRole::Resident,
            ]);

            $alreadyLivesThere = $unit->residencies()
                ->where('user_id', $user->id)
                ->whereNull('moved_out_at')
                ->exists();

            if ($alreadyLivesThere) {
                return response()->json(['message' => 'This person already lives in this unit.'], 409);
            }

            $residency = $unit->residencies()->create([
                'user_id' => $user->id,
                'relationship' => $request->validated('relationship'),
                'moved_in_at' => $request->validated('moved_in_at') ?? today(),
            ]);

            return new ResidencyResource($residency->load('user'));
        });
    }

    public function moveOut(Request $request, Residency $residency)
    {
        $rules = ['nullable', 'date', 'before_or_equal:today'];

        if ($residency->moved_in_at) {
            $rules[] = 'after_or_equal:'.$residency->moved_in_at->toDateString();
        }

        $data = $request->validate(['moved_out_at' => $rules]);

        if ($residency->moved_out_at) {
            return response()->json(['message' => 'This resident has already moved out.'], 409);
        }

        $residency->update(['moved_out_at' => $data['moved_out_at'] ?? today()]);

        return new ResidencyResource($residency->load('user'));
    }
}
