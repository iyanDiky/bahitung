<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CorrectionController;
use App\Http\Controllers\Api\V1\GoalController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HouseholdController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\OperationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.v1.health');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register')->name('auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login')->name('auth.login');
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    Route::get('/households', [HouseholdController::class, 'index'])->name('households.index');
    Route::post('/households', [HouseholdController::class, 'store'])->name('households.store');
    Route::get('/households/{household}', [HouseholdController::class, 'show'])->whereNumber('household')->name('households.show');
    Route::patch('/households/{household}', [HouseholdController::class, 'update'])->whereNumber('household')->name('households.update');
    Route::get('/households/{household}/members', [HouseholdController::class, 'members'])->whereNumber('household')->name('households.members');
});

Route::post('/auth/forgot-password', [AccountController::class, 'forgot'])->middleware('throttle:account-recovery')->name('password.email');
Route::post('/auth/reset-password', [AccountController::class, 'reset'])->middleware('throttle:account-recovery')->name('password.update');
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/email/verification-notification', [AccountController::class, 'resend'])->middleware('throttle:email-verification')->name('verification.send');
    Route::get('/auth/email/verify/{id}/{hash}', [AccountController::class, 'verify'])->middleware(['signed:relative', 'throttle:email-verification'])->whereNumber('id')->name('verification.verify');
    Route::get('/households/{household}/invitations', [MembershipController::class, 'index'])->whereNumber('household')->name('invitations.index');
    Route::middleware('throttle:membership-write')->group(function (): void {
        Route::post('/households/{household}/invitations', [MembershipController::class, 'invite'])->whereNumber('household')->name('invitations.store');
        Route::post('/households/{household}/invitations/{invitation}/revoke', [MembershipController::class, 'revoke'])->whereNumber(['household', 'invitation'])->name('invitations.revoke');
        Route::post('/invitations/accept', [MembershipController::class, 'accept'])->name('invitations.accept');
        Route::patch('/households/{household}/members/{member}', [MembershipController::class, 'update'])->whereNumber(['household', 'member'])->name('members.update');
        Route::post('/households/{household}/ownership-transfer', [MembershipController::class, 'transfer'])->whereNumber('household')->name('ownership.transfer');
    });
});

Route::post('/session/register', [AuthController::class, 'sessionRegister'])->middleware('throttle:auth-register')->name('session.register');
Route::post('/session/login', [AuthController::class, 'sessionLogin'])->middleware('throttle:auth-login')->name('session.login');
Route::post('/session/logout', [AuthController::class, 'sessionLogout'])->middleware('auth:sanctum')->name('session.logout');

Route::middleware('auth:sanctum')->prefix('/households/{household}/wallets')->whereNumber('household')->group(function (): void {
    Route::get('/', [WalletController::class, 'index'])->name('wallets.index');
    Route::post('/', [WalletController::class, 'store'])->name('wallets.store');
    Route::get('/{wallet}', [WalletController::class, 'show'])->whereNumber('wallet')->name('wallets.show');
    Route::patch('/{wallet}', [WalletController::class, 'update'])->whereNumber('wallet')->name('wallets.update');
    Route::get('/{wallet}/movements', [WalletController::class, 'movements'])->whereNumber('wallet')->name('wallets.movements');
});

Route::middleware('auth:sanctum')->prefix('households/{household}')->whereNumber('household')->group(function (): void {
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])->whereNumber('category')->name('categories.update');
    Route::get('/operations', [OperationController::class, 'index'])->name('operations.index');
    Route::post('/operations', [OperationController::class, 'store'])->name('operations.store');
    Route::get('/operations/{operation}', [OperationController::class, 'show'])->whereNumber('operation')->name('operations.show');
});

Route::middleware('auth:sanctum')->prefix('households/{household}/operations/{operation}')->whereNumber(['household', 'operation'])->group(function (): void {
    Route::post('/correct', [CorrectionController::class, 'correct'])->name('operations.correct');
    Route::post('/void', [CorrectionController::class, 'void'])->name('operations.void');
});

Route::middleware('auth:sanctum')->prefix('households/{household}')->whereNumber('household')->group(function (): void {
    Route::get('/budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::post('/budgets', [BudgetController::class, 'store'])->name('budgets.store');
    Route::post('/budgets/copy', [BudgetController::class, 'copy'])->name('budgets.copy');
    Route::get('/budgets/{budget}', [BudgetController::class, 'show'])->whereNumber('budget')->name('budgets.show');
    Route::patch('/budgets/{budget}', [BudgetController::class, 'update'])->whereNumber('budget')->name('budgets.update');
    Route::get('/reports/budget-usage', [BudgetController::class, 'usage'])->name('budgets.usage');
});

Route::middleware('auth:sanctum')->prefix('households/{household}/goals')->whereNumber('household')->group(function (): void {
    Route::get('/', [GoalController::class, 'index'])->name('goals.index');
    Route::post('/', [GoalController::class, 'store'])->name('goals.store');
    Route::get('/{goal}', [GoalController::class, 'show'])->whereNumber('goal')->name('goals.show');
    Route::patch('/{goal}', [GoalController::class, 'update'])->whereNumber('goal')->name('goals.update');
    Route::get('/{goal}/movements', [GoalController::class, 'movements'])->whereNumber('goal')->name('goals.movements');
    Route::post('/{goal}/contributions', [GoalController::class, 'contribute'])->whereNumber('goal')->name('goals.contributions');
    Route::post('/{goal}/withdrawals', [GoalController::class, 'withdraw'])->whereNumber('goal')->name('goals.withdrawals');
});

Route::get('households/{household}/reports/cashflow', [ReportController::class, 'cashflow'])->middleware('auth:sanctum')->whereNumber('household')->name('reports.cashflow');
