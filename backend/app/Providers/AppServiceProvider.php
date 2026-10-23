<?php

namespace App\Providers;

use App\Policies\MembershipPolicy;
use App\Policies\OperationPolicy;
use App\Policies\WalletPolicy;
use App\Repositories\Contracts\AuthRepository;
use App\Repositories\Contracts\BudgetRepository;
use App\Repositories\Contracts\DatabaseHealthRepository;
use App\Repositories\Contracts\FinanceRepository;
use App\Repositories\Contracts\GoalRepository;
use App\Repositories\Contracts\HouseholdRepository;
use App\Repositories\Contracts\MembershipRepository;
use App\Repositories\Contracts\ReportRepository;
use App\Repositories\Contracts\WalletRepository;
use App\Repositories\EloquentAuthRepository;
use App\Repositories\EloquentBudgetRepository;
use App\Repositories\EloquentFinanceRepository;
use App\Repositories\EloquentGoalRepository;
use App\Repositories\EloquentHouseholdRepository;
use App\Repositories\EloquentMembershipRepository;
use App\Repositories\EloquentReportRepository;
use App\Repositories\EloquentWalletRepository;
use App\Repositories\PostgresDatabaseHealthRepository;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReportRepository::class, EloquentReportRepository::class);
        $this->app->bind(GoalRepository::class, EloquentGoalRepository::class);
        $this->app->bind(BudgetRepository::class, EloquentBudgetRepository::class);
        $this->app->bind(FinanceRepository::class, EloquentFinanceRepository::class);
        $this->app->bind(WalletRepository::class, EloquentWalletRepository::class);
        $this->app->bind(MembershipRepository::class, EloquentMembershipRepository::class);
        $this->app->bind(AuthRepository::class, EloquentAuthRepository::class);
        $this->app->bind(HouseholdRepository::class, EloquentHouseholdRepository::class);
        $this->app->bind(DatabaseHealthRepository::class, fn () => new PostgresDatabaseHealthRepository(DB::connection()));
    }

    public function boot(): void
    {
        Gate::define('view-reports', [WalletPolicy::class, 'viewAny']);
        Gate::define('view-goals', [WalletPolicy::class, 'viewAny']);
        Gate::define('manage-goals', [WalletPolicy::class, 'manage']);
        Gate::define('view-budgets', [WalletPolicy::class, 'viewAny']);
        Gate::define('manage-budgets', [WalletPolicy::class, 'manage']);
        Gate::define('view-categories', [WalletPolicy::class, 'viewAny']);
        Gate::define('manage-categories', [WalletPolicy::class, 'manage']);
        Gate::define('view-operations', [WalletPolicy::class, 'viewAny']);
        Gate::define('correct-operations', [OperationPolicy::class, 'correct']);
        Gate::define('post-operations', [OperationPolicy::class, 'create']);
        Gate::define('view-wallets', [WalletPolicy::class, 'viewAny']);
        Gate::define('manage-wallets', [WalletPolicy::class, 'manage']);
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
        VerifyEmail::createUrlUsing(fn ($user) => URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())], absolute: false));
        VerifyEmail::toMailUsing(fn ($user, string $url) => (new MailMessage)->subject('Verifikasi email bahitung')->line('Konfirmasikan alamat email Anda.')->action('Verifikasi email', rtrim(config('app.frontend_url'), '/').'/verifikasi-email?'.http_build_query(['url' => $url]))->line('Tautan berlaku 60 menit.'));
        Gate::define('manage-members', [MembershipPolicy::class, 'manage']);
        Gate::define('change-member', [MembershipPolicy::class, 'change']);
        Gate::define('transfer-owner', [MembershipPolicy::class, 'transfer']);
        RateLimiter::for('account-recovery', fn (Request $request) => Limit::perMinute(5)->by('recovery:'.$request->ip()));
        RateLimiter::for('email-verification', fn (Request $request) => Limit::perMinute(5)->by('verification:'.$request->user()->id));
        RateLimiter::for('membership-write', fn (Request $request) => Limit::perMinute(20)->by('membership:'.$request->user()->id));

        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.hash('sha256', strtolower(trim(is_string($request->input('email')) ? $request->input('email') : '')).'|'.$request->ip())),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by('register:'.$request->ip()));
    }
}
