<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\RoleName;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'employee_id', 'is_active', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Should this user land in the full back-office (admin) experience?
     * Anyone with a genuine financial or management permission does; a plain
     * Employee / Team Leader gets the minimal operational dashboard.
     */
    public function usesAdminExperience(): bool
    {
        if ($this->hasRole(RoleName::SuperAdmin->value)) {
            return true;
        }

        return $this->hasAnyPermission([
            'finance.view', 'invoices.view', 'payments.view', 'expenses.view',
            'payroll.view', 'accounting.view', 'accounts.view', 'reports.financial',
            'employees.view', 'customers.view', 'settings.view', 'reports.operational',
        ]);
    }
}
