<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Leave = 'leave';
    case RemoteWork = 'remote_work';
    case ExternalMission = 'external_mission';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'حاضر',
            self::Late => 'متأخر',
            self::Absent => 'غائب',
            self::Leave => 'إجازة',
            self::RemoteWork => 'عمل عن بعد',
            self::ExternalMission => 'مهمة خارجية',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Present => 'bg-emerald-50 text-emerald-700',
            self::Late => 'bg-amber-50 text-amber-700',
            self::Absent => 'bg-red-50 text-red-700',
            self::Leave => 'bg-brand-50 text-brand-700',
            self::RemoteWork => 'bg-violet-50 text-violet-700',
            self::ExternalMission => 'bg-slate-100 text-slate-600',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
