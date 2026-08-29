<x-print-layout :title="'قسيمة راتب '.$item->employee_name_snapshot">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #1f47f5; padding-bottom:14px;">
        <div class="brand">
            <div class="logo">S</div>
            <div>
                <h1>{{ $company['name'] }}</h1>
                <p class="muted" style="margin:4px 0 0; font-size:12px;">
                    {{ $company['address'] }}
                    @if ($company['phone']) · هاتف: <span class="num">{{ $company['phone'] }}</span>@endif
                </p>
            </div>
        </div>
        <div style="text-align:left;">
            <h1 style="color:#1f47f5;">قسيمة راتب</h1>
            <p class="num muted" style="margin:4px 0 0;">{{ $item->payrollRun->periodLabel() }}</p>
        </div>
    </div>

    <div style="display:flex; justify-content:space-between; margin-top:18px; font-size:13px;">
        <div>
            <p style="margin:0; font-weight:600;">{{ $item->employee_name_snapshot }}</p>
            @if ($item->job_title_snapshot)<p class="muted" style="margin:2px 0;">{{ $item->job_title_snapshot }}</p>@endif
            @if ($item->department_snapshot)<p class="muted" style="margin:2px 0;">{{ $item->department_snapshot }}</p>@endif
        </div>
        <div style="text-align:left;" class="muted" style="font-size:12px;">
            <p style="margin:0 0 4px;">أيام العمل: <span class="num">{{ (int) $item->working_days }}</span></p>
            <p style="margin:0 0 4px;">أيام الحضور: <span class="num">{{ (int) $item->attended_days }}</span></p>
            <p style="margin:0;">أيام الغياب: <span class="num">{{ (int) $item->absent_days }}</span></p>
        </div>
    </div>

    <table class="items" style="margin-top:18px;">
        <thead><tr><th>الاستحقاقات</th><th class="num">المبلغ (₪)</th></tr></thead>
        <tbody>
            <tr><td>الراتب الأساسي</td><td class="num">{{ number_format((float) $item->base_salary_ils, 2) }}</td></tr>
            @if ((float) $item->overtime_amount_ils > 0)<tr><td>ساعات إضافية</td><td class="num">{{ number_format((float) $item->overtime_amount_ils, 2) }}</td></tr>@endif
            @if ((float) $item->allowances_ils > 0)<tr><td>بدلات</td><td class="num">{{ number_format((float) $item->allowances_ils, 2) }}</td></tr>@endif
            @if ((float) $item->bonuses_ils > 0)<tr><td>مكافآت</td><td class="num">{{ number_format((float) $item->bonuses_ils, 2) }}</td></tr>@endif
            @if ((float) $item->commissions_ils > 0)<tr><td>عمولات</td><td class="num">{{ number_format((float) $item->commissions_ils, 2) }}</td></tr>@endif
            <tr style="font-weight:700; border-top:1px solid #1f47f5;"><td>إجمالي الاستحقاقات</td><td class="num">{{ number_format((float) $item->gross_salary_ils, 2) }}</td></tr>
        </tbody>
    </table>

    <table class="items" style="margin-top:14px;">
        <thead><tr><th>الاستقطاعات</th><th class="num">المبلغ (₪)</th></tr></thead>
        <tbody>
            @if ((float) $item->absence_deduction_ils > 0)<tr><td>خصم غياب</td><td class="num">{{ number_format((float) $item->absence_deduction_ils, 2) }}</td></tr>@endif
            @if ((float) $item->late_deduction_ils > 0)<tr><td>خصم تأخير</td><td class="num">{{ number_format((float) $item->late_deduction_ils, 2) }}</td></tr>@endif
            @if ((float) $item->unpaid_leave_deduction_ils > 0)<tr><td>إجازة غير مدفوعة</td><td class="num">{{ number_format((float) $item->unpaid_leave_deduction_ils, 2) }}</td></tr>@endif
            @if ((float) $item->other_deductions_ils > 0)<tr><td>استقطاعات أخرى</td><td class="num">{{ number_format((float) $item->other_deductions_ils, 2) }}</td></tr>@endif
            @if ((float) $item->advances_deduction_ils > 0)<tr><td>استرداد سلفة</td><td class="num">{{ number_format((float) $item->advances_deduction_ils, 2) }}</td></tr>@endif
            <tr style="font-weight:700; border-top:1px solid #e2e8f0;"><td>إجمالي الاستقطاعات</td><td class="num">{{ number_format((float) $item->total_deductions_ils, 2) }}</td></tr>
        </tbody>
    </table>

    <div style="display:flex; justify-content:flex-start; margin-top:16px;">
        <table class="totals" style="width:320px;">
            <tr style="border-top:2px solid #1f47f5;"><td style="font-weight:700;">صافي الراتب</td><td class="num" style="font-weight:700; font-size:18px;">{{ number_format((float) $item->net_salary_ils, 2) }} ₪</td></tr>
            <tr><td class="muted">المدفوع</td><td class="num">{{ number_format((float) $item->paid_amount_ils, 2) }} ₪</td></tr>
            <tr><td class="muted">المتبقي</td><td class="num">{{ number_format((float) $item->remaining_payable_ils, 2) }} ₪</td></tr>
        </table>
    </div>

    <div style="margin-top:30px; display:flex; justify-content:space-between; font-size:12px;" class="muted">
        <div>توقيع الموظف: ____________________</div>
        <div>توقيع الإدارة: ____________________</div>
    </div>
</x-print-layout>
