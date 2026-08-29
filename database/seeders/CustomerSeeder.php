<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $categories = ['شركات', 'مطاعم', 'متاجر', 'مؤسسات', 'أطباء', 'عقارات', 'أفراد', 'أخرى'];
        foreach ($categories as $i => $name) {
            CustomerCategory::updateOrCreate(['name' => $name], ['is_active' => true, 'sort_order' => $i]);
        }

        $catId = fn (string $name) => CustomerCategory::where('name', $name)->value('id');

        // Act as the GM so created_by is populated.
        Auth::login(User::where('email', 'gm@superapple.ps')->first() ?? User::first());
        $service = app(CustomerService::class);

        $rows = [
            ['شركة الأفق', 'شركات', 'active', 'facebook', 'رام الله'],
            ['مطعم المدينة', 'مطاعم', 'active', 'instagram', 'نابلس'],
            ['مركز التقنية', 'شركات', 'active', 'referral', 'رام الله'],
            ['متجر البيت', 'متاجر', 'lead', 'whatsapp', 'الخليل'],
            ['شركة البناء الحديث', 'عقارات', 'active', 'website', 'بيت لحم'],
            ['عيادة الابتسامة', 'أطباء', 'active', 'existing_relationship', 'رام الله'],
            ['مؤسسة النور', 'مؤسسات', 'on_hold', 'referral', 'جنين'],
            ['متجر الأناقة', 'متاجر', 'active', 'instagram', 'طولكرم'],
            ['شركة الريادة', 'شركات', 'lead', 'facebook', 'رام الله'],
            ['عقارات المستقبل', 'عقارات', 'inactive', 'other', 'قلقيلية'],
        ];

        foreach ($rows as $i => [$name, $cat, $status, $source, $city]) {
            $number = 'CUS-'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT);
            if (Customer::where('customer_number', $number)->exists()) {
                continue;
            }

            $service->create([
                'customer_number' => $number,
                'name' => $name,
                'contact_person' => 'مسؤول '.$name,
                'phone' => '059'.random_int(1000000, 9999999),
                'whatsapp_number' => '059'.random_int(1000000, 9999999),
                'city' => $city,
                'customer_category_id' => $catId($cat),
                'status' => $status,
                'source' => $source,
                'is_active' => $status !== 'inactive',
            ]);
        }

        Auth::logout();
    }
}
