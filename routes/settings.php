<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\UsersController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

// Admin-only user management — see docs/self-hosting.md's authorization
// model (Phase 7.1.2, PART H/J).
Route::middleware(['auth', 'admin'])->prefix('settings/users')->name('settings.users.')->group(function () {
    Route::get('/', [UsersController::class, 'index'])->name('index');
    Route::get('create', [UsersController::class, 'create'])->name('create');
    Route::post('/', [UsersController::class, 'store'])->name('store');
    Route::get('{user}/edit', [UsersController::class, 'edit'])->name('edit');
    Route::patch('{user}', [UsersController::class, 'update'])->name('update');
    Route::put('{user}/password', [UsersController::class, 'updatePassword'])->name('password.update');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
