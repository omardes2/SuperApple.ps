<?php

namespace App\Enums;

enum WhatsAppMessageStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Queued => 'في قائمة الإرسال',
            self::Sent => 'أُرسلت',
            self::Delivered => 'وصلت',
            self::Read => 'قُرئت',
            self::Failed => 'فشلت',
            self::Cancelled => 'أُلغيت',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending, self::Queued => 'bg-slate-100 text-slate-600',
            self::Sent => 'bg-blue-50 text-blue-700',
            self::Delivered => 'bg-teal-50 text-teal-700',
            self::Read => 'bg-emerald-50 text-emerald-700',
            self::Failed => 'bg-red-50 text-red-700',
            self::Cancelled => 'bg-slate-200 text-slate-500',
        };
    }

    /** Terminal states never transition further. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Read, self::Failed, self::Cancelled], true);
    }

    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
