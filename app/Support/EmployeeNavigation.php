<?php

namespace App\Support;

/**
 * Employee-portal sidebar definition — the single source of truth for the
 * employee navigation. Mirrors the shape of AdminNavigation so the shared shell
 * renders both the same way. Strictly operational: no financial or
 * administrative sections exist here, and each item still declares the
 * permission needed to see it (real enforcement lives on routes + policies).
 *
 * @see AdminNavigation
 */
final class EmployeeNavigation
{
    /**
     * @return list<array{label:string,items:list<array{route:?string,label:string,icon:string,permission:string}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'label' => 'الرئيسية',
                'items' => [
                    ['route' => 'employee.dashboard', 'label' => 'الرئيسية', 'icon' => 'home', 'permission' => 'dashboard.view'],
                ],
            ],
            [
                'label' => 'العمل',
                'items' => [
                    ['route' => 'employee.tasks', 'label' => 'مهامي', 'icon' => 'check', 'permission' => 'tasks.view_own'],
                    ['route' => 'employee.attendance', 'label' => 'الدوام', 'icon' => 'clock', 'permission' => 'attendance.view_own'],
                    ['route' => 'employee.leaves', 'label' => 'الإجازات', 'icon' => 'calendar', 'permission' => 'leaves.view_own'],
                    ['route' => 'employee.payslips', 'label' => 'قسائم راتبي', 'icon' => 'wallet', 'permission' => 'payslips.view_own'],
                ],
            ],
            [
                'label' => 'النظام',
                'items' => [
                    ['route' => 'employee.notifications', 'label' => 'الإشعارات', 'icon' => 'bell', 'permission' => 'notifications.view'],
                ],
            ],
        ];
    }
}
