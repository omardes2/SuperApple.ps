<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\RoleName;
use App\Support\AdminNavigation;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'employee_id', 'is_active', 'locale', 'last_login_at', 'notification_preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** Notification categories a user may toggle in their preferences. */
    public const NOTIFICATION_CATEGORIES = ['tasks', 'hr', 'finance', 'whatsapp'];

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
            'last_login_at' => 'datetime',
            'notification_preferences' => 'array',
        ];
    }

    /** Does this user want notifications in the given category? Default: yes. */
    public function wantsNotification(string $category): bool
    {
        $prefs = $this->notification_preferences ?? [];

        return (bool) ($prefs[$category] ?? true);
    }

    /**
     * The operational employee profile this login account belongs to (if any).
     * Kept as an application-level link (see employees migration note).
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Should this user land in the full back-office (admin) experience?
     * Permission-driven, not role-name-driven: anyone who holds ANY permission
     * that unlocks a back-office sidebar item belongs in the admin experience.
     * This is derived from AdminNavigation (the single source of truth) minus
     * the handful of permissions the employee portal also exposes
     * (dashboard/notifications), so a custom role with any real admin
     * permission — tasks.view included — reaches the admin area automatically,
     * while a plain Employee / Team Leader stays in the operational dashboard.
     */
    public function usesAdminExperience(): bool
    {
        if ($this->hasRole(RoleName::SuperAdmin->value)) {
            return true;
        }

        return $this->hasAnyPermission(self::adminExperiencePermissions());
    }

    /**
     * Back-office sidebar permissions that define admin access, excluding the
     * ones the employee portal shares (so employees are never misrouted).
     *
     * @return list<string>
     */
    public static function adminExperiencePermissions(): array
    {
        $shared = ['dashboard.view', 'notifications.view'];

        return collect(AdminNavigation::groups())
            ->flatMap(fn ($group) => Arr::pluck($group['items'], 'permission'))
            ->reject(fn ($permission) => in_array($permission, $shared, true))
            ->unique()
            ->values()
            ->all();
    }
}
