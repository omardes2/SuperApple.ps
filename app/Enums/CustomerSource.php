<?php

namespace App\Enums;

enum CustomerSource: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case WhatsApp = 'whatsapp';
    case Referral = 'referral';
    case Website = 'website';
    case ExistingRelationship = 'existing_relationship';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'فيسبوك',
            self::Instagram => 'إنستغرام',
            self::WhatsApp => 'واتساب',
            self::Referral => 'ترشيح',
            self::Website => 'الموقع',
            self::ExistingRelationship => 'علاقة سابقة',
            self::Other => 'أخرى',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
