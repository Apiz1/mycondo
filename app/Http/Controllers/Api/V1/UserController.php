<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffUserRequest;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'role'   => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $users = User::query()
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")
            ))
            ->orderBy('name')
            ->paginate(15);

        return UserResource::collection($users);
    }

    public function store(StoreStaffUserRequest $request)
    {
        $data = $request->validated();

        $user = new User([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'], // hashed by the model cast
        ]);
        $user->role = UserRole::from($data['role']);
        $user->status = 'active';
        $user->save();

        ActivityLog::record('user.created', "Created {$user->role->value} account for {$user->email}", $user, ['role' => $user->role->value]);

        return new UserResource($user);
    }

    public function deactivate(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 403);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'This account is already inactive.'], 409);
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['status' => 'inactive'])->save();
            $user->tokens()->delete(); // end any session that is still open

            ActivityLog::record('user.deactivated', "Deactivated {$user->email}", $user);
        });

        return new UserResource($user);
    }

    public function activate(User $user)
    {
        if ($user->status === 'active') {
            return response()->json(['message' => 'This account is already active.'], 409);
        }

        $user->forceFill(['status' => 'active'])->save();

        ActivityLog::record('user.activated', "Reactivated {$user->email}", $user);

        return new UserResource($user);
    }

    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', Password::min(8)],
        ]);

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => $data['password']])->save();
            $user->tokens()->delete();

            // never put the password itself in the log
            ActivityLog::record('user.password_reset', "Reset password for {$user->email}", $user);
        });

        return new UserResource($user);
    }
}