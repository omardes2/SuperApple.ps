<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\User;
use App\Services\ServiceCatalogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        Auth::login(User::where('email', 'accountant@superapple.ps')->first() ?? User::first());
        $service = app(ServiceCatalogService::class);

        // name, category, type, price_usd, cost_ils
        $rows = [
            ['إدارة صفحات السوشيال ميديا', 'سوشيال ميديا', 'monthly', 400, 600],
            ['تصميم منشورات', 'تصميم', 'custom', 150, 200],
            ['تصميم شعار', 'تصميم', 'one_time', 250, 300],
            ['تصميم هوية بصرية', 'تصميم', 'one_time', 900, 1200],
            ['كتابة محتوى', 'محتوى', 'monthly', 200, 250],
            ['إدارة حملات إعلانية', 'إعلانات', 'monthly', 500, 700],
            ['تصوير منتجات', 'تصوير', 'custom', 300, 450],
            ['تصوير فيديو', 'تصوير', 'custom', 600, 900],
            ['مونتاج فيديو', 'إنتاج', 'custom', 350, 500],
            ['تصميم موقع إلكتروني', 'برمجة', 'one_time', 1500, 2200],
            ['تصميم متجر إلكتروني', 'برمجة', 'one_time', 2500, 3800],
            ['استضافة', 'برمجة', 'yearly', 120, 150],
            ['دومين', 'برمجة', 'yearly', 20, 25],
            ['SEO', 'تسويق', 'monthly', 350, 500],
            ['طباعة', 'إنتاج', 'custom', 100, 180],
            ['إنتاج إعلاني', 'إنتاج', 'custom', 1200, 1800],
        ];

        foreach ($rows as $i => [$name, $category, $type, $price, $cost]) {
            $code = 'SRV-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
            if (Service::where('service_code', $code)->exists()) {
                continue;
            }

            $service->create([
                'service_code' => $code,
                'name' => $name,
                'category' => $category,
                'service_type' => $type,
                'default_price_usd' => $price,
                'estimated_cost_ils' => $cost,
                'tax_rate' => 16,
                'is_active' => true,
            ]);
        }

        Auth::logout();
    }
}
