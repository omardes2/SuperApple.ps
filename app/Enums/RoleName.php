<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'Super Admin';
    case GeneralManager = 'General Manager';
    case Accountant = 'Accountant';
    case HrManager = 'HR Manager';
    case ProjectManager = 'Project Manager';
    case TeamLeader = 'Team Leader';
    case Employee = 'Employee';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'مدير النظام',
            self::GeneralManager => 'المدير العام',
            self::Accountant => 'محاسب',
            self::HrManager => 'مدير الموارد البشرية',
            self::ProjectManager => 'مدير مشاريع',
            self::TeamLeader => 'قائد فريق',
            self::Employee => 'موظف',
        };
    }

    /**
     * Roles that use the minimal employee experience (no financial/admin sidebar).
     */
    public function isOperationalOnly(): bool
    {
        return in_array($this, [self::Employee, self::TeamLeader], true);
    }
}
