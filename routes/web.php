<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FindingsController;
use App\Http\Controllers\Projects\ProjectFindingsController;
use App\Http\Controllers\Projects\ProjectScansController;
use App\Http\Controllers\Projects\ProjectsController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('projects', [ProjectsController::class, 'index'])->name('projects.index');
    Route::get('projects/{project:public_id}', [ProjectsController::class, 'show'])->name('projects.show');
    Route::get('projects/{project:public_id}/findings', [ProjectFindingsController::class, 'index'])->name('projects.findings');
    Route::get('projects/{project:public_id}/scans', [ProjectScansController::class, 'index'])->name('projects.scans');
    Route::get('projects/{project:public_id}/scans/{scan:public_id}', [ProjectScansController::class, 'show'])->name('projects.scans.show');

    Route::get('findings/{finding:public_id}', [FindingsController::class, 'show'])->name('findings.show');
    Route::patch('findings/{finding:public_id}/status', [FindingsController::class, 'updateStatus'])->name('findings.status');
});

require __DIR__.'/settings.php';
