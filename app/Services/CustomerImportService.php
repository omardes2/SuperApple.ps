<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use RuntimeException;

/**
 * Parses an Aliphia-style customer export (xlsx/xls/csv) into a validated,
 * previewable set of rows, then imports them using ONLY the official domain
 * services — CustomerService (numbering) and CustomerOpeningBalanceService
 * (posted Dr/Cr journal). No fake invoices, no parallel accounting.
 *
 * Currency: the source file carries ILS balances. The official customer opening
 * balance is USD, so we recompute usd = |ils| / rate and hand USD + rate to the
 * opening-balance service (which snapshots ils = usd × rate right back). Any
 * USD column already in the file is only cross-checked, never trusted blindly.
 *
 * Nothing is persisted during parse/preview.
 */
class CustomerImportService
{
    /** Rounding tolerance (money scale) allowed between file-USD and recomputed USD. */
    public const USD_TOLERANCE = '0.02';

    public const STATUS_READY = 'ready';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_WARNING = 'warning';

    public const STATUS_ERROR = 'error';

    public const ACTION_IMPORT = 'import';

    public const ACTION_SKIP = 'skip';

    public const ACTION_ATTACH = 'attach';

    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerOpeningBalanceService $openingBalances,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Header aliases → canonical field. Compared after normalizeHeader().
     *
     * @return array<string,string>
     */
    private function headerAliases(): array
    {
        $map = [
            'name' => ['اسم العميل', 'العميل', 'الاسم', 'اسم', 'name', 'customer', 'customer name', 'client'],
            'whatsapp' => ['رقم واتساب', 'رقم الواتساب', 'واتساب', 'الواتساب', 'whatsapp', 'whatsapp number', 'رقم الجوال', 'الجوال', 'الهاتف', 'phone', 'mobile'],
            'type' => ['نوع الرصيد', 'النوع', 'نوع', 'type', 'balance type', 'الحالة'],
            'ils' => ['الرصيد الاصلي', 'الرصيد الأصلي', 'الرصيد بالشيكل', 'الرصيد شيكل', 'الرصيد ils', 'الرصيد', 'المبلغ', 'balance', 'balance ils', 'ils', 'amount', 'شيكل'],
            'rate' => ['سعر الصرف', 'سعر صرف', 'الصرف', 'exchange rate', 'rate', 'fx', 'fx rate'],
            'usd' => ['الرصيد بالدولار', 'الرصيد دولار', 'الرصيد usd', 'usd', 'balance usd', 'amount usd', 'دولار'],
            'date' => ['تاريخ الرصيد', 'التاريخ', 'تاريخ', 'date', 'balance date'],
            'notes' => ['ملاحظات', 'ملاحظة', 'notes', 'note', 'remarks', 'بيان'],
        ];

        $flat = [];
        foreach ($map as $field => $aliases) {
            foreach ($aliases as $alias) {
                $flat[$this->normalizeHeader($alias)] = $field;
            }
        }

        return $flat;
    }

    /** Canonical header labels for the downloadable template (first = preferred). */
    public static function templateHeaders(): array
    {
        return ['اسم العميل', 'رقم واتساب', 'نوع الرصيد', 'الرصيد الأصلي', 'سعر الصرف', 'الرصيد بالدولار', 'تاريخ الرصيد', 'ملاحظات'];
    }

    // ---- Parse + validate -------------------------------------------------

    /**
     * Read a spreadsheet file and produce the full preview payload. Never writes.
     *
     * @return array{
     *   ok: bool,
     *   error: ?string,
     *   rows: list<array<string,mixed>>,
     *   stats: array<string,mixed>,
     *   warnings: list<string>
     * }
     */
    public function preview(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false); // keep number formats so dates resolve
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            return $this->fail('تعذّر قراءة الملف. تأكد أنه ملف Excel صالح (xlsx / xls / csv).');
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColIdx = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        // Map header columns.
        $aliases = $this->headerAliases();
        $columns = []; // field => column index (1-based)
        for ($c = 1; $c <= $highestColIdx; $c++) {
            $raw = (string) $sheet->getCell([$c, 1])->getValue();
            $key = $this->normalizeHeader($raw);
            if ($key === '') {
                continue;
            }
            if (isset($aliases[$key]) && ! isset($columns[$aliases[$key]])) {
                $columns[$aliases[$key]] = $c;
            }
        }

        // Required header: name. Balance posting also needs type + rate when an
        // amount column exists — otherwise we cannot form a valid journal.
        if (! isset($columns['name'])) {
            return $this->fail('عمود "اسم العميل" مفقود في الملف. لا يمكن المتابعة.');
        }
        $warnings = [];
        $hasIls = isset($columns['ils']);
        if ($hasIls) {
            $missing = [];
            if (! isset($columns['type'])) {
                $missing[] = 'نوع الرصيد';
            }
            if (! isset($columns['rate'])) {
                $missing[] = 'سعر الصرف';
            }
            if ($missing !== []) {
                return $this->fail('يوجد عمود رصيد لكن الأعمدة التالية مفقودة: '.implode('، ', $missing).'. لا يمكن ترحيل الأرصدة بدونها.');
            }
        } else {
            $warnings[] = 'لم يتم العثور على عمود الرصيد — سيتم إنشاء العملاء فقط بدون أرصدة افتتاحية.';
        }

        // Existing-customer index for duplicate detection (name-first).
        [$byName, $byNameWhatsapp] = $this->existingIndexes();
        $seenNames = []; // normalized name => first line, for in-file duplicates

        $rows = [];
        for ($r = 2; $r <= $highestRow; $r++) {
            $name = $this->cellString($sheet, $columns['name'] ?? null, $r);
            $whatsapp = $this->cellString($sheet, $columns['whatsapp'] ?? null, $r);
            $typeRaw = $this->cellString($sheet, $columns['type'] ?? null, $r);
            $ilsRaw = $this->cellString($sheet, $columns['ils'] ?? null, $r);
            $rateRaw = $this->cellString($sheet, $columns['rate'] ?? null, $r);
            $usdRaw = $this->cellString($sheet, $columns['usd'] ?? null, $r);
            $notes = $this->cellString($sheet, $columns['notes'] ?? null, $r);
            $dateVal = isset($columns['date']) ? $this->cellDate($sheet, $columns['date'], $r) : null;

            // Skip fully blank rows silently.
            if ($name === '' && $whatsapp === '' && $ilsRaw === '' && $typeRaw === '') {
                continue;
            }

            $rows[] = $this->buildRow(
                line: $r,
                name: $name,
                whatsapp: $whatsapp,
                typeRaw: $typeRaw,
                ilsRaw: $ilsRaw,
                rateRaw: $rateRaw,
                usdRaw: $usdRaw,
                dateVal: $dateVal,
                notes: $notes,
                byName: $byName,
                byNameWhatsapp: $byNameWhatsapp,
                seenNames: $seenNames,
            );
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'ok' => true,
            'error' => null,
            'rows' => $rows,
            'stats' => $this->computeStats($rows),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string,int>  $byName  normalized name => customer id
     * @param  array<string,int>  $byNameWhatsapp  normalized "name|whatsapp" => id
     * @param  array<string,int>  $seenNames  running in-file name map (by reference)
     * @return array<string,mixed>
     */
    private function buildRow(
        int $line,
        string $name,
        string $whatsapp,
        string $typeRaw,
        string $ilsRaw,
        string $rateRaw,
        string $usdRaw,
        ?string $dateVal,
        string $notes,
        array $byName,
        array $byNameWhatsapp,
        array &$seenNames,
    ): array {
        $messages = [];
        $status = self::STATUS_READY;

        $normName = $this->normalizeName($name);
        $normWhatsapp = $this->normalizeWhatsapp($whatsapp);

        // --- Name (required) ---
        if ($normName === '') {
            $messages[] = 'اسم العميل مطلوب.';
            $status = self::STATUS_ERROR;
        }

        // --- Balance amount ---
        $ilsAbs = '';
        $hasBalance = false;
        if (trim($ilsRaw) !== '') {
            if (! is_numeric(str_replace([',', ' '], '', $ilsRaw))) {
                $messages[] = 'قيمة الرصيد غير رقمية.';
                $status = self::STATUS_ERROR;
            } else {
                $ilsClean = str_replace([',', ' '], '', $ilsRaw);
                $ilsAbs = Money::money(Money::of($ilsClean)->abs());
                $hasBalance = Money::isPositive($ilsAbs);
            }
        }

        // --- Type ---
        $type = $this->parseType($typeRaw);

        // --- Rate ---
        $rate = null;
        if (trim($rateRaw) !== '') {
            $rateClean = str_replace([',', ' '], '', $rateRaw);
            if (is_numeric($rateClean) && Money::isPositive($rateClean)) {
                $rate = Money::rate($rateClean);
            }
        }

        $usd = '';
        $balanceDate = $dateVal;

        if ($hasBalance && $status !== self::STATUS_ERROR) {
            if ($type === null) {
                $messages[] = 'نوع الرصيد يجب أن يكون "مدين" أو "دائن".';
                $status = self::STATUS_ERROR;
            }
            if ($rate === null) {
                $messages[] = 'سعر الصرف مطلوب ويجب أن يكون أكبر من صفر.';
                $status = self::STATUS_ERROR;
            }
            if (blank($balanceDate)) {
                $messages[] = 'تاريخ الرصيد مطلوب أو غير صالح.';
                $status = self::STATUS_ERROR;
            }

            if ($status !== self::STATUS_ERROR) {
                // Official amount = |ILS| / rate, recomputed — never trust the file's USD.
                $usd = Money::convertIlsToUsd($ilsAbs, $rate);

                if (Money::isZeroOrNegative($usd)) {
                    $messages[] = 'قيمة الرصيد بالدولار الناتجة تساوي صفراً.';
                    $status = self::STATUS_ERROR;
                }

                // Cross-check against any USD column in the file.
                $usdClean = str_replace([',', ' '], '', $usdRaw);
                if (trim($usdRaw) !== '' && is_numeric($usdClean)) {
                    $fileUsd = Money::money(Money::of($usdClean)->abs());
                    if (Money::isGreaterThan(Money::absDiff($fileUsd, $usd), self::USD_TOLERANCE)) {
                        $messages[] = "تعارض في التحويل: الملف يذكر {$fileUsd}\$ بينما |الشيكل|÷السعر = {$usd}\$.";
                        $status = self::STATUS_ERROR;
                    }
                }
            }
        }

        // --- Duplicate detection (name-first; WhatsApp never alone) ---
        $existingId = null;
        $existingName = null;
        $hasExistingOb = false;

        if ($normName !== '') {
            // In-file duplicate name.
            if (isset($seenNames[$normName])) {
                $messages[] = 'اسم مكرر داخل الملف (صف '.$seenNames[$normName].').';
                if ($status === self::STATUS_READY) {
                    $status = self::STATUS_WARNING;
                }
            } else {
                $seenNames[$normName] = $line;
            }

            if (isset($byName[$normName])) {
                $existingId = $byName[$normName];
                $existingName = $name;
                $strong = $normWhatsapp !== '' && isset($byNameWhatsapp[$normName.'|'.$normWhatsapp]);
                $messages[] = $strong
                    ? 'عميل موجود (تطابق الاسم والواتساب).'
                    : 'عميل موجود بنفس الاسم — يرجى المراجعة.';
                if ($status !== self::STATUS_ERROR) {
                    $status = self::STATUS_DUPLICATE;
                }

                $hasExistingOb = Customer::whereKey($existingId)
                    ->whereHas('openingBalances', fn ($q) => $q->posted())->exists();
                if ($hasExistingOb && $hasBalance) {
                    $messages[] = 'العميل لديه رصيد افتتاحي مسبقاً — لن يُضاف رصيد ثانٍ.';
                }
            }
        }

        // --- Default action ---
        if ($status === self::STATUS_ERROR) {
            $action = self::ACTION_SKIP;
        } elseif ($existingId !== null) {
            $action = self::ACTION_SKIP; // never silently mutate an existing customer
        } else {
            $action = self::ACTION_IMPORT;
        }

        return [
            'line' => $line,
            'name' => trim($name),
            'whatsapp' => trim($whatsapp) !== '' ? trim($whatsapp) : null,
            'type' => $type,
            'ils' => $hasBalance ? $ilsAbs : '',
            'rate' => $hasBalance ? $rate : null,
            'usd' => $usd,
            'balance_date' => $balanceDate,
            'notes' => trim($notes) !== '' ? trim($notes) : null,
            'has_balance' => $hasBalance,
            'status' => $status,
            'messages' => $messages,
            'existing_customer_id' => $existingId,
            'existing_customer_name' => $existingName,
            'has_existing_ob' => $hasExistingOb,
            'action' => $action,
        ];
    }

    // ---- Commit -----------------------------------------------------------

    /**
     * Import the confirmed rows atomically. Only rows the preview marked as a
     * genuine action (new import, or an explicit attach to an existing customer)
     * take effect; error/skip rows are ignored. Re-validates everything against
     * the live DB inside the transaction — never trusts client-supplied numbers.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $meta  filename, row_count
     * @return array<string,mixed> final report
     */
    public function import(array $rows, array $meta = []): array
    {
        return DB::transaction(function () use ($rows, $meta) {
            $createdCustomers = 0;
            $existingUsed = 0;
            $openingBalances = 0;
            $zeroBalance = 0;
            $skipped = 0;
            $debitIls = '0.00';
            $creditIls = '0.00';
            $debitUsd = '0.00';
            $creditUsd = '0.00';

            foreach ($rows as $row) {
                $action = $row['action'] ?? self::ACTION_SKIP;
                if (($row['status'] ?? null) === self::STATUS_ERROR || $action === self::ACTION_SKIP) {
                    $skipped++;

                    continue;
                }

                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    $skipped++;

                    continue;
                }

                // Resolve the customer (existing attach vs new create).
                if ($action === self::ACTION_ATTACH && ! empty($row['existing_customer_id'])) {
                    $customer = Customer::find($row['existing_customer_id']);
                    if (! $customer) {
                        throw new RuntimeException("العميل المرتبط بالصف {$row['line']} لم يعد موجوداً.");
                    }
                    $existingUsed++;
                } else {
                    $customer = $this->customers->create([
                        'name' => $name,
                        'whatsapp_number' => $row['whatsapp'] ?: null, // real null, never a fake number
                        'notes' => $row['notes'] ?: null,
                        'status' => CustomerStatus::Active->value,
                        'is_active' => true,
                    ]);
                    $createdCustomers++;
                }

                // Opening balance (only when there is a real, non-zero amount).
                if (! empty($row['has_balance'])) {
                    // Guard: never a second posted opening balance.
                    if ($customer->openingBalances()->posted()->exists()) {
                        continue;
                    }

                    $type = $row['type'];
                    $usd = Money::convertIlsToUsd($row['ils'], $row['rate']); // recompute server-side
                    $ils = Money::convertUsdToIls($usd, $row['rate']);

                    $this->openingBalances->create($customer, [
                        'type' => $type,
                        'amount_usd' => $usd,
                        'exchange_rate' => $row['rate'],
                        'balance_date' => $row['balance_date'],
                        'notes' => $row['notes'] ?: 'مستورد من Excel',
                    ]);
                    $openingBalances++;

                    if ($type === CustomerOpeningBalance::TYPE_DEBIT) {
                        $debitIls = Money::add($debitIls, $ils);
                        $debitUsd = Money::add($debitUsd, $usd);
                    } else {
                        $creditIls = Money::add($creditIls, $ils);
                        $creditUsd = Money::add($creditUsd, $usd);
                    }
                } else {
                    $zeroBalance++;
                }
            }

            $report = [
                'created_customers' => $createdCustomers,
                'existing_used' => $existingUsed,
                'opening_balances' => $openingBalances,
                'zero_balance' => $zeroBalance,
                'skipped' => $skipped,
                'debit_ils' => $debitIls,
                'credit_ils' => $creditIls,
                'debit_usd' => $debitUsd,
                'credit_usd' => $creditUsd,
                'net_ils' => Money::subtract($debitIls, $creditIls),
            ];

            // Audit — summary only, never the spreadsheet contents.
            $this->audit->log('customers_imported', null, 'Customers',
                new: [
                    'filename' => $meta['filename'] ?? null,
                    'rows' => $meta['row_count'] ?? count($rows),
                    'created_customers' => $createdCustomers,
                    'existing_used' => $existingUsed,
                    'opening_balances' => $openingBalances,
                    'debit_ils' => $debitIls,
                    'credit_ils' => $creditIls,
                ],
                description: "استيراد عملاء من Excel: {$createdCustomers} عميل جديد، {$openingBalances} رصيد افتتاحي.");

            return $report;
        });
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * @return array{0:array<string,int>,1:array<string,int>}
     */
    private function existingIndexes(): array
    {
        $byName = [];
        $byNameWhatsapp = [];
        Customer::query()->select(['id', 'name', 'whatsapp_number'])->chunk(500, function ($chunk) use (&$byName, &$byNameWhatsapp) {
            foreach ($chunk as $c) {
                $nn = $this->normalizeName((string) $c->name);
                if ($nn === '') {
                    continue;
                }
                // First occurrence wins as the canonical match target.
                $byName[$nn] ??= $c->id;
                $nw = $this->normalizeWhatsapp((string) $c->whatsapp_number);
                if ($nw !== '') {
                    $byNameWhatsapp[$nn.'|'.$nw] ??= $c->id;
                }
            }
        });

        return [$byName, $byNameWhatsapp];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function computeStats(array $rows): array
    {
        $stats = [
            'total_rows' => count($rows),
            'new_customers' => 0,
            'existing_customers' => 0,
            'debit_count' => 0,
            'credit_count' => 0,
            'zero_count' => 0,
            'total_debit_ils' => '0.00',
            'total_credit_ils' => '0.00',
            'total_debit_usd' => '0.00',
            'total_credit_usd' => '0.00',
            'errors' => 0,
            'warnings' => 0,
            'duplicates' => 0,
        ];

        foreach ($rows as $row) {
            if ($row['status'] === self::STATUS_ERROR) {
                $stats['errors']++;
            } elseif ($row['status'] === self::STATUS_WARNING) {
                $stats['warnings']++;
            } elseif ($row['status'] === self::STATUS_DUPLICATE) {
                $stats['duplicates']++;
            }

            if ($row['existing_customer_id'] !== null) {
                $stats['existing_customers']++;
            } else {
                $stats['new_customers']++;
            }

            if (! empty($row['has_balance']) && $row['status'] !== self::STATUS_ERROR) {
                if ($row['type'] === CustomerOpeningBalance::TYPE_DEBIT) {
                    $stats['debit_count']++;
                    $stats['total_debit_ils'] = Money::add($stats['total_debit_ils'], $row['ils']);
                    $stats['total_debit_usd'] = Money::add($stats['total_debit_usd'], $row['usd']);
                } elseif ($row['type'] === CustomerOpeningBalance::TYPE_CREDIT) {
                    $stats['credit_count']++;
                    $stats['total_credit_ils'] = Money::add($stats['total_credit_ils'], $row['ils']);
                    $stats['total_credit_usd'] = Money::add($stats['total_credit_usd'], $row['usd']);
                }
            } else {
                $stats['zero_count']++;
            }
        }

        $stats['net_ils'] = Money::subtract($stats['total_debit_ils'], $stats['total_credit_ils']);

        return $stats;
    }

    /** @return array{ok:bool,error:string,rows:array,stats:array,warnings:array} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'rows' => [], 'stats' => [], 'warnings' => []];
    }

    private function cellString($sheet, ?int $col, int $row): string
    {
        if ($col === null) {
            return '';
        }
        $value = $sheet->getCell([$col, $row])->getValue();
        if ($value === null) {
            return '';
        }
        // A whole float (e.g. a phone read as number) must not become "9.7E+11".
        if (is_float($value)) {
            $value = $value == floor($value) ? sprintf('%.0f', $value) : (string) $value;
        }

        return trim((string) $value);
    }

    private function cellDate($sheet, int $col, int $row): ?string
    {
        $cell = $sheet->getCell([$col, $row]);
        $value = $cell->getValue();
        if ($value === null || $value === '') {
            return null;
        }

        // Native Excel date serials.
        if (is_numeric($value) && SpreadsheetDate::isDateTime($cell)) {
            try {
                return SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->parseTextDate(trim((string) $value));
    }

    /** Arabic files use DD/MM/YYYY; try that first, then a few safe fallbacks. */
    private function parseTextDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'd/m/y'] as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $value);
                if ($dt !== false && $dt->format($fmt) === $value) {
                    return $dt->format('Y-m-d');
                }
            } catch (\Throwable) {
                // try next format
            }
        }

        return null;
    }

    private function parseType(string $raw): ?string
    {
        $n = $this->normalizeHeader($raw);
        $debit = ['مدين', 'مدينة', 'debit', 'dr', 'dr.', 'د'];
        $credit = ['دائن', 'دائنة', 'credit', 'cr', 'cr.', 'ك'];
        if (in_array($n, $debit, true)) {
            return CustomerOpeningBalance::TYPE_DEBIT;
        }
        if (in_array($n, $credit, true)) {
            return CustomerOpeningBalance::TYPE_CREDIT;
        }

        return null;
    }

    private function normalizeHeader(string $h): string
    {
        $h = str_replace(["\u{0640}", ':', '*', '#'], '', $h); // tatweel + punctuation
        $h = preg_replace('/[\s\x{00A0}\x{200E}\x{200F}]+/u', ' ', $h) ?? $h;

        return mb_strtolower(trim($h), 'UTF-8');
    }

    /** Normalized for comparison only — the real name is always stored verbatim. */
    private function normalizeName(string $name): string
    {
        $name = str_replace("\u{0640}", '', $name);
        $name = preg_replace('/[\s\x{00A0}\x{200E}\x{200F}\x{FEFF}]+/u', ' ', $name) ?? $name;

        return mb_strtolower(trim($name), 'UTF-8');
    }

    /** Digits only; compare on the trailing 9 to tolerate country-code prefixes. */
    private function normalizeWhatsapp(string $w): string
    {
        $digits = preg_replace('/\D+/', '', $w) ?? '';
        if ($digits === '') {
            return '';
        }

        return mb_strlen($digits) > 9 ? substr($digits, -9) : $digits;
    }
}
