<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SessionAuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_spa_registration_starts_session_without_issuing_bearer_token(): void
    {
        Notification::fake();
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/session/register', ['name' => 'Session User', 'email' => 'session@example.test', 'password' => 'session-password-123', 'password_confirmation' => 'session-password-123'])
            ->assertCreated()->assertJsonMissingPath('data.token')->assertSessionHas('password_hash_web');
        $this->assertAuthenticated('web');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Notification::assertSentTo(User::where('email', 'session@example.test')->sole(), VerifyEmail::class);
    }

    public function test_spa_login_authenticates_me_and_logout_invalidates_session(): void
    {
        $user = User::factory()->create(['password' => 'session-password-123']);
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/session/login', ['email' => $user->email, 'password' => 'session-password-123'])->assertOk()->assertJsonMissingPath('data.token');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->postJson('/api/v1/session/logout')->assertOk();
        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_session_login_requires_a_stateful_request(): void
    {
        $this->postJson('/api/v1/session/login', ['email' => 'session@example.test', 'password' => 'session-password-123'])->assertStatus(419);
    }

    public function test_password_change_invalidates_existing_spa_session(): void
    {
        $user = User::factory()->create(['password' => 'session-password-123']);
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/session/login', ['email' => $user->email, 'password' => 'session-password-123'])->assertOk();
        $user->forceFill(['password' => 'replacement-password-123'])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_csrf_cookie_cors_allows_only_configured_frontend_credentials(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')->get('/sanctum/csrf-cookie')->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->withHeader('Origin', 'https://evil.example')->get('/sanctum/csrf-cookie')->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
