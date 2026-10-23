<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\AuthService;
use App\Services\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, AuthService $auth): JsonResponse
    {
        return response()->json(['data' => $auth->register($request->validated()), 'warnings' => []], 201)->header('Cache-Control', 'no-store');
    }

    public function login(LoginRequest $request, AuthService $auth): JsonResponse
    {
        return response()->json(['data' => $auth->login($request->validated()), 'warnings' => []])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request, AuthService $auth): JsonResponse
    {
        $auth->logout($request->user());

        return response()->json(['data' => ['message' => 'Berhasil keluar.'], 'warnings' => []]);
    }

    public function sessionRegister(RegisterRequest $request, AuthService $auth): JsonResponse
    {
        abort_unless($request->hasSession(), 419);
        $result = $auth->register($request->validated(), issueToken: false);

        return $this->startSession($request, $result['user'], 201);
    }

    public function sessionLogin(LoginRequest $request, AuthService $auth): JsonResponse
    {
        abort_unless($request->hasSession(), 419);
        $result = $auth->login($request->validated(), issueToken: false);

        return $this->startSession($request, $result['user']);
    }

    private function startSession(Request $request, User $user, int $status = 200): JsonResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->password));

        return response()->json(['data' => ['user' => $user], 'warnings' => []], $status)->header('Cache-Control', 'no-store');
    }

    public function sessionLogout(Request $request): JsonResponse
    {
        abort_unless($request->hasSession(), 419);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['message' => 'Berhasil keluar.'], 'warnings' => []]);
    }

    public function me(Request $request, HouseholdService $households): JsonResponse
    {
        return response()->json(['data' => ['user' => $request->user(), 'memberships' => $households->memberships($request->user())], 'warnings' => []])->header('Cache-Control', 'no-store');
    }
}
