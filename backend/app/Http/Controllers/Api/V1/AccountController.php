<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\AccountService;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function resend(Request $request): JsonResponse
    {
        $this->accounts->sendVerification($request->user());

        return $this->message('Jika email belum terverifikasi, tautan verifikasi telah dikirim.');
    }

    public function verify(EmailVerificationRequest $request): JsonResponse
    {
        $this->accounts->verify($request);

        return $this->message('Email berhasil diverifikasi.');
    }

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->accounts->forgot($request->validated('email'));

        return $this->message('Jika email terdaftar, tautan reset kata sandi akan dikirim.');
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->accounts->reset($request->validated());

        return $this->message('Kata sandi berhasil diubah. Silakan login kembali.');
    }

    private function message(string $message): JsonResponse
    {
        return response()->json(['data' => ['message' => $message], 'warnings' => []])->header('Cache-Control', 'no-store');
    }
}
