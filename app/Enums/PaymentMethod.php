<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case CreditCard = 'credit_card';
    case OnlinePayment = 'online_payment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'نقداً',
            self::BankTransfer => 'حوالة بنكية',
            self::Cheque => 'شيك',
            self::CreditCard => 'بطاقة ائتمان',
            self::OnlinePayment => 'دفع إلكتروني',
            self::Other => 'أخرى',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
