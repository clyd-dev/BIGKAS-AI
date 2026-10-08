<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthApiController extends Controller
{
    /**
     * Login via API
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::byEmail($validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return $this->error('Invalid credentials', 401);
        }

        if (! $user->is_active) {
            return $this->error('Account is deactivated', 403);
        }

        // Create Sanctum token scoped to the user's role abilities
        $token = $user->createToken('api-token', $this->tokenAbilities($user->role))->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 'Login successful');
    }

    /**
     * Register via API
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'email' => ['required', 'email', \App\Rules\UniqueBlindIndex::email()],
            'password' => 'required|string|min:8|confirmed|regex:/[a-z]/|regex:/[A-Z]/|regex:/[0-9]/',
            'role' => 'required|in:teacher,parent',
            'phone' => 'nullable|string',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'school_id' => \App\Models\School::orderBy('id')->value('id'),
        ]);

        // role and is_active are guarded — set via explicit assignment.
        $user->role = $validated['role'];
        $user->is_active = true;
        $user->save();

        \App\Notifications\NewUserRegistered::notifyAdmins($user);

        $token = $user->createToken('api-token', $this->tokenAbilities($user->role))->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 'Registration successful', 201);
    }

    /**
     * Logout via API (revoke current token)
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success([], 'Logged out successfully');
    }

    /**
     * Get authenticated user info
     */
    public function currentUser(Request $request): JsonResponse
    {
        $user = $request->user()->load('school');

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone,
                'school' => $user->school ? [
                    'id' => $user->school->id,
                    'name' => $user->school->name,
                ] : null,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    /**
     * Request a password reset link via API.
     * Always returns a generic message to avoid user enumeration.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink($request->only('email'));

        return $this->success([], 'If an account with that email exists, we have sent a password reset link.');
    }

    /**
     * Reset password via API.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed|regex:/[a-z]/|regex:/[A-Z]/|regex:/[0-9]/',
        ]);

        $status = Password::reset(
            $request->only('email', 'token', 'password', 'password_confirmation'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->error('Unable to reset password. Please try again.', 422);
        }

        return $this->success([], 'Your password has been reset.');
    }

    // ----- Token ability mapping -----

    /**
     * Map a user role to Sanctum token abilities.
     *
     * Note: abilities are recorded on the token only; no route or
     * controller enforces them yet, so this changes nothing at runtime
     * today. Enforcement can be added later via tokenCan() checks.
     *
     * @return string[]
     */
    protected function tokenAbilities(?string $role): array
    {
        return match ($role) {
            'admin' => ['*'],
            'teacher' => ['assessment', 'learner', 'intervention', 'material', 'report', 'message'],
            'parent' => ['learner', 'message'],
            default => [],
        };
    }

    // ----- JSON helper methods -----

    protected function success(array $data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error(string $message, int $status, array $errors = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if (! empty($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
