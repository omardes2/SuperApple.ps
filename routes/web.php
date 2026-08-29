<?php

use App\Livewire\Admin\AuditLogPage;
use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Admin\SettingsPage;
use App\Livewire\Auth\Login;
use App\Livewire\Employee\Dashboard as EmployeeDashboard;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! Auth::check()) {
        return redirect()->route('login');
    }

    return redirect()->route(
        Auth::user()->usesAdminExperience() ? 'admin.dashboard' : 'employee.dashboard'
    );
});

// ---- Guest ----
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

// ---- Authenticated ----
Route::middleware('auth')->group(function () {
    Route::post('/logout', function (AuditLogger $audit) {
        $audit->log('logout', Auth::user(), 'Auth', description: 'تسجيل خروج');
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    // ---- Admin / back-office ----
    Route::prefix('admin')->name('admin.')->middleware('admin.area')->group(function () {
        Route::get('/', AdminDashboard::class)->middleware('can:dashboard.view')->name('dashboard');
        Route::get('/settings', SettingsPage::class)->middleware('can:settings.view')->name('settings');
        Route::get('/audit-log', AuditLogPage::class)->middleware('can:audit.view')->name('audit');
    });

    // ---- Employee / operational ----
    Route::prefix('employee')->name('employee.')->group(function () {
        Route::get('/', EmployeeDashboard::class)->middleware('can:dashboard.view')->name('dashboard');
    });
});
