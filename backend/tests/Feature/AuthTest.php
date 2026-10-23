<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_register_hashes_password_and_returns_expiring_token_without_accepting_privileges(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi', 'email' => 'BUDI@example.test', 'password' => 'a-long-password-123', 'password_confirmation' => 'a-long-password-123',
            'email_verified_at' => now(), 'role' => 'owner',
        ])->assertCreated()->assertJsonPath('data.user.email', 'budi@example.test')->assertJsonMissingPath('data.user.password')->assertJsonPath('data.user.email_verified_at', null);
        $user = User::where('email', 'budi@example.test')->sole();
        $this->assertTrue(Hash::check('a-long-password-123', $user->password));
        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertSame($user->id, $token->tokenable_id);
        $this->assertNotSame($response->json('data.token'), $token->token);
        $this->assertNotNull($token->expires_at);
        $this->assertDatabaseCount('household_members', 0);
    }

    public function test_login_accepts_normalized_email_and_valid_password(): void
    {
        $user = User::factory()->create(['email' => 'budi@example.test', 'password' => 'a-long-password-123']);
        $this->postJson('/api/v1/auth/login', ['email' => ' BUDI@example.test ', 'password' => 'a-long-password-123'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id)->assertJsonStructure(['data' => ['token', 'expires_at']])->assertJsonMissingPath('data.user.password');
        $this->assertSame(1, $user->tokens()->count());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_invalid_login_has_generic_error_and_creates_no_token(bool $existing): void
    {
        if ($existing) {
            User::factory()->create(['email' => 'wrong@example.test']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'wrong@example.test', 'password' => 'incorrect'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_revokes_only_current_token_and_it_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');
        $other = $user->createToken('other');
        $this->withToken($current->plainTextToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($current->plainTextToken));
        $this->assertNotNull(PersonalAccessToken::findToken($other->plainTextToken));
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired', ['*'], now()->subMinute());
        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[TestWith(['/api/v1/me'])]
    #[TestWith(['/api/v1/households'])]
    #[TestWith(['/api/v1/households/1'])]
    #[TestWith(['/api/v1/households/1/members'])]
    public function test_protected_reads_require_auth_without_accept_header(string $path): void
    {
        $this->get($path)->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED')->assertJsonStructure(['request_id']);
    }

    #[TestWith(['/api/v1/auth/logout', 'POST'])]
    #[TestWith(['/api/v1/households', 'POST'])]
    #[TestWith(['/api/v1/households/1', 'PATCH'])]
    public function test_protected_writes_require_auth(string $path, string $method): void
    {
        $this->json($method, $path, ['name' => 'Forbidden'])->assertUnauthorized();
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withToken('invalid-token')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_registration_requires_fields(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['name', 'email', 'password']]]);
    }

    public function test_registration_rejects_duplicate_email_case_insensitively(): void
    {
        User::factory()->create(['email' => 'budi@example.test']);
        $this->postJson('/api/v1/auth/register', ['name' => 'Budi', 'email' => 'BUDI@example.test', 'password' => 'a-long-password-123', 'password_confirmation' => 'a-long-password-123'])
            ->assertUnprocessable()->assertJsonPath('error.details.email.0', 'Email sudah terdaftar.');
        $this->assertDatabaseCount('users', 1);
    }

    #[TestWith(['password', 'short', 'Kata sandi minimal 12 karakter.'])]
    #[TestWith(['password_confirmation', 'different-password', 'Konfirmasi kata sandi tidak cocok.'])]
    #[TestWith(['email', 'invalid', 'Format email tidak valid.'])]
    public function test_registration_validates_input(string $field, string $value, string $message): void
    {
        $payload = ['name' => 'Budi', 'email' => 'budi@example.test', 'password' => 'a-long-password-123', 'password_confirmation' => 'a-long-password-123'];
        $payload[$field] = $value;
        $errorField = $field === 'password_confirmation' ? 'password' : $field;
        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable()->assertJsonPath('error.details.'.$errorField.'.0', $message);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_throttle_handles_malformed_email_and_limits_attempts(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => [], 'password' => 'incorrect'])->assertUnprocessable();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'rate@example.test', 'password' => 'incorrect'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'RATE@example.test', 'password' => 'incorrect'])
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
    }
}
