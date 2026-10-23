<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    private function token(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    public function test_registration_sends_signed_verification_without_verifying_user(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', ['name' => 'Budi', 'email' => 'budi@example.test', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'])->assertCreated();
        $user = User::where('email', 'budi@example.test')->sole();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class, function ($notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringContainsString('signature%3D', $url);
            $this->assertStringStartsWith(config('app.frontend_url').'/verifikasi-email?', $url);

            return true;
        });
    }

    public function test_signed_verification_updates_email_and_can_be_retried(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
        $this->token($user);
        $this->getJson($url)->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->getJson($url)->assertOk();
    }

    #[TestWith(['expired'])]
    #[TestWith(['tampered'])]
    #[TestWith(['other-user'])]
    public function test_verification_rejects_invalid_links(string $case): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', $case === 'expired' ? now()->subMinute() : now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
        $this->token($case === 'other-user' ? User::factory()->unverified()->create() : $user);
        if ($case === 'tampered') {
            $url .= 'tampered';
        }
        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_is_throttled_and_skips_verified_users(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->token($user);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        }
        $this->postJson('/api/v1/auth/email/verification-notification')->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyEmail::class, 5);
        $verified = User::factory()->create();
        $this->token($verified);
        $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        Notification::assertNotSentTo($verified, VerifyEmail::class);
    }

    public function test_forgot_password_is_generic_and_does_not_expose_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'budi@example.test']);
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'BUDI@example.test'])->assertOk()->assertJsonMissingPath('data.token');
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): bool {
            $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
            $this->assertNotSame($notification->token, $stored);
            $this->assertTrue(Hash::check($notification->token, $stored));
            $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?', $notification->toMail($user)->actionUrl);

            return true;
        });
    }

    public function test_reset_changes_password_revokes_all_access_tokens_and_consumes_reset_token(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $access = $user->createToken('existing')->plainTextToken;
        $user->createToken('another-device');
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'];
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($access)->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[TestWith(['wrong-email'])]
    #[TestWith(['invalid'])]
    #[TestWith(['expired'])]
    public function test_invalid_reset_does_not_change_password(string $case): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $token = Password::createToken($user);
        if ($case === 'expired') {
            $this->travel(61)->minutes();
        }
        $this->postJson('/api/v1/auth/reset-password', ['email' => $case === 'wrong-email' ? 'unknown@example.test' : $user->email, 'token' => $case === 'invalid' ? 'invalid' : $token, 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])->assertUnprocessable();
        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_reset_requires_password_confirmation_and_limits_public_requests(): void
    {
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'budi@example.test', 'token' => 'x', 'password' => 'short'])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['password']]]);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk();
        }
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.test'])->assertStatus(429);
    }
}
