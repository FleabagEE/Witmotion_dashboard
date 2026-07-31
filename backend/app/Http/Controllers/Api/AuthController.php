<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        // Throttled per email and IP together, so one account cannot be
        // brute-forced from many addresses nor one address across many accounts.
        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '
                    .RateLimiter::availableIn($key).' seconds.',
            ])->status(429);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 300);
            $this->audit->recordFailure(
                'auth.login', 'Failed sign-in for '.$data['email'], $request,
                'user', $user?->id === null ? null : (string) $user->id,
            );
            // Deliberately identical for unknown email and wrong password: the
            // difference tells an attacker which accounts exist.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->active) {
            RateLimiter::hit($key, 300);
            $this->audit->recordFailure(
                'auth.login', 'Sign-in refused: account disabled', $request, 'user', (string) $user->id,
            );
            throw ValidationException::withMessages(['email' => 'This account is disabled.']);
        }

        RateLimiter::clear($key);
        $user->forceFill(['last_login_at' => now()])->save();

        $abilities = Roles::abilitiesFor($user->role);
        $token = $user->createToken($data['device_name'] ?? 'dashboard', $abilities);
        $this->audit->record(
            action: 'auth.login',
            subjectType: 'user',
            subjectId: (string) $user->id,
            summary: "Signed in as {$user->role}",
            request: $request,
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'abilities' => $abilities,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'abilities' => Roles::abilitiesFor($user->role),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->record(
            action: 'auth.logout',
            subjectType: 'user',
            subjectId: (string) $request->user()->id,
            request: $request,
        );
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'signed out']);
    }
}
