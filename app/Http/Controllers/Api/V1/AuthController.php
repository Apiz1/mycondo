<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            ActivityLog::record('security.login_failed', 'Failed sign-in attempt', null, ['email' => $credentials['email']]);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Your account is not active.'], 403);
        }

        ActivityLog::record('auth.login', 'Signed in', null, [], $user);

        return response()->json([
            'token' => $user->createToken('mycondo-api')->plainTextToken,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        ActivityLog::record('auth.logout', 'Signed out');

        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
