<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Who may use this appliance, and what they may do.
 *
 * Restricted to `administer` at the route. Roles map to abilities in
 * App\Support\Roles; nothing here invents a permission model of its own.
 *
 * THE TWO RULES THAT ARE NOT NEGOTIABLE
 * ------------------------------------
 *
 * An administrator cannot remove their own administrator role, and cannot
 * deactivate themselves. Both are one click from an appliance nobody can
 * administer, on a machine in a plant room that may have no other route in.
 * The check is here rather than in the UI because a UI that hides a button
 * still permits the request.
 *
 * Passwords are set, never read. A reset issues a new one and revokes every
 * existing token for that user, because a password change that leaves old
 * sessions alive has not actually locked anybody out.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $users = User::query()
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->present($u));

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'role' => ['required', Rule::in(Roles::ALL)],
            'password' => ['required', Password::min(12)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => Hash::make($data['password']),
            'active' => true,
        ]);

        $this->audit->record(
            action: 'user.created',
            subjectType: 'user',
            subjectId: (string) $user->id,
            summary: sprintf('%s created as %s', $user->email, $user->role),
            after: ['role' => $user->role],
            request: $request,
        );

        return response()->json(['data' => $this->present($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'role' => ['sometimes', Rule::in(Roles::ALL)],
            'active' => ['sometimes', 'boolean'],
        ]);

        $actor = $request->user();
        $isSelf = $actor && $actor->id === $user->id;

        if ($isSelf && array_key_exists('role', $data) && $data['role'] !== Roles::ADMINISTRATOR) {
            return response()->json([
                'message' => 'You cannot remove your own administrator role. '
                    . 'Ask another administrator, so the appliance is never left without one.',
            ], 422);
        }

        if ($isSelf && array_key_exists('active', $data) && $data['active'] === false) {
            return response()->json([
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        // The last administrator standing. Demoting or disabling them leaves an
        // appliance in a plant room that nobody can configure.
        if ($user->role === Roles::ADMINISTRATOR) {
            $losingAdmin = (array_key_exists('role', $data) && $data['role'] !== Roles::ADMINISTRATOR)
                || (array_key_exists('active', $data) && $data['active'] === false);

            $others = User::where('role', Roles::ADMINISTRATOR)
                ->where('active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($losingAdmin && $others === 0) {
                return response()->json([
                    'message' => 'This is the only active administrator. '
                        . 'Promote somebody else first.',
                ], 422);
            }
        }

        $before = ['role' => $user->role, 'active' => $user->active];
        $user->fill($data)->save();

        // A deactivated account with live tokens is not deactivated.
        if (array_key_exists('active', $data) && $data['active'] === false) {
            $user->tokens()->delete();
        }

        $this->audit->record(
            action: 'user.updated',
            subjectType: 'user',
            subjectId: (string) $user->id,
            summary: sprintf('%s updated', $user->email),
            before: $before,
            after: ['role' => $user->role, 'active' => $user->active],
            request: $request,
        );

        return response()->json(['data' => $this->present($user)]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', Password::min(12)],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        // Every existing session for this user, gone. Otherwise the person you
        // just locked out is still logged in on the tablet in the site office.
        $revoked = $user->tokens()->count();
        $user->tokens()->delete();

        $this->audit->record(
            action: 'user.password_reset',
            subjectType: 'user',
            subjectId: (string) $user->id,
            summary: sprintf('%s password reset, %d session(s) revoked', $user->email, $revoked),
            request: $request,
        );

        return response()->json(['data' => $this->present($user), 'sessions_revoked' => $revoked]);
    }

    /** The roles a client can offer, with what each may actually do. */
    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (string $role) => [
                'role' => $role,
                'abilities' => Roles::abilitiesFor($role),
            ], Roles::ALL),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'abilities' => Roles::abilitiesFor($user->role),
            'active' => (bool) $user->active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
