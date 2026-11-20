<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $email = $credentials['email'];
        $user = User::where('email', $email)->first();

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            $remainingMinutes = (int) ceil(now()->diffInSeconds($user->locked_until) / 60);
            if ($remainingMinutes < 1) {
                $remainingMinutes = 1;
            }

            return response()->json([
                'message' => "Your account is locked. Try again in {$remainingMinutes} minutes.",
            ], 423);
        }

        if (! Auth::attempt($credentials)) {
            if ($user) {
                if ($user->locked_until && $user->locked_until->isPast()) {
                    $user->failed_login_attempts = 0;
                    $user->locked_until = null;
                }

                $user->failed_login_attempts++;

                if ($user->failed_login_attempts >= 5) {
                    $user->locked_until = now()->addMinutes(15);
                    $user->save();

                    AuditLog::query()->create([
                        'user_id' => $user->id,
                        'category' => 'security',
                        'action' => 'auth.account_locked',
                        'resource_type' => 'user',
                        'resource_id' => $user->id,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'metadata' => [
                            'locked_until' => $user->locked_until->toISOString(),
                        ],
                        'created_at' => now(),
                    ]);

                    $lockTime = $user->locked_until->copy()->timezone('America/Mexico_City')->format('H:i');

                    return response()->json([
                        'message' => "Your account has been locked for 15 minutes due to too many failed sign-in attempts. Try again at {$lockTime}.",
                    ], 423);
                }

                $user->save();
            }

            AuditLog::query()->create([
                'user_id' => $user?->id,
                'category' => 'security',
                'action' => 'auth.login_failed',
                'resource_type' => 'user',
                'resource_id' => $user?->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'attempted_email' => $email,
                ],
                'created_at' => now(),
            ]);

            return response()->json(['message' => 'The email or password is incorrect.'], 401);
        }

        $user = $request->user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();

            return response()->json(['message' => 'Your account is inactive. Contact your administrator.'], 403);
        }

        // Stateful Sanctum SPA requests have a session. Token-authenticated
        // requests do not, and must not fail after a successful login.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();

        AuditLog::query()->create([
            'user_id' => $user->id,
            'category' => 'security',
            'action' => 'login',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [],
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'user_id' => $user->id,
            'category' => 'security',
            'action' => 'auth.login_succeeded',
            'resource_type' => 'user',
            'resource_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [],
            'created_at' => now(),
        ]);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'organization_id' => $user->organization_id,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'organization_id' => $user->organization_id,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            AuditLog::query()->create([
                'user_id' => $user->id,
                'category' => 'security',
                'action' => 'auth.logout',
                'resource_type' => 'user',
                'resource_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [],
                'created_at' => now(),
            ]);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }
}
