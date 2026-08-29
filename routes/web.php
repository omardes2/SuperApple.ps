<?php

use App\Livewire\Admin\AttendanceIndex;
use App\Livewire\Admin\AuditLogPage;
use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Admin\DepartmentsIndex;
use App\Livewire\Admin\EmployeeProfile;
use App\Livewire\Admin\EmployeesIndex;
use App\Livewire\Admin\LeavesIndex;
use App\Livewire\Admin\SettingsPage;
use App\Livewire\Auth\Login;
use App\Livewire\Employee\Dashboard as EmployeeDashboard;
use App\Livewire\Employee\MyAttendance;
use App\Livewire\Employee\MyLeaves;
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

        // HR — Sprint 1
        Route::get('/departments', DepartmentsIndex::class)->middleware('can:departments.view')->name('departments');
        Route::get('/employees', EmployeesIndex::class)->middleware('can:employees.view')->name('employees');
        Route::get('/employees/{employee}', EmployeeProfile::class)->middleware('can:employees.view')->name('employees.show');
        Route::get('/attendance', AttendanceIndex::class)->middleware('can:attendance.view')->name('attendance');
        Route::get('/leaves', LeavesIndex::class)->middleware('can:leaves.view')->name('leaves');

        Route::get('/settings', SettingsPage::class)->middleware('can:settings.view')->name('settings');
        Route::get('/audit-log', AuditLogPage::class)->middleware('can:audit.view')->name('audit');
    });

    // ---- Employee / operational ----
    Route::prefix('employee')->name('employee.')->group(function () {
        Route::get('/', EmployeeDashboard::class)->middleware('can:dashboard.view')->name('dashboard');
        Route::get('/attendance', MyAttendance::class)->middleware('can:attendance.view_own')->name('attendance');
        Route::get('/leaves', MyLeaves::class)->middleware('can:leaves.view_own')->name('leaves');
    });
});
