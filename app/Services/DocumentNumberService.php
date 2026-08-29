<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Atomic, gap-tolerant auto numbering for business documents.
 * Formats are configurable via settings (group: numbering).
 *
 * Tokens: {PREFIX} {YEAR} {SEQ:width}
 * Examples of defaults:
 *   customer   -> CUS-00001
 *   project    -> PRJ-2026-0001
 *   task       -> TSK-000001
 *   quotation  -> QUO-2026-0001
 *   invoice    -> INV-2026-0001
 *   payment    -> PAY-2026-0001
 *   expense    -> EXP-2026-0001
 *   payroll    -> PAYROLL-2026-08
 */
class DocumentNumberService
{
    /** @var array<string,array{prefix:string,year:bool,width:int}> */
    private array $defaults = [
        'customer' => ['prefix' => 'CUS', 'year' => false, 'width' => 5],
        'project' => ['prefix' => 'PRJ', 'year' => true,  'width' => 4],
        'task' => ['prefix' => 'TSK', 'year' => false, 'width' => 6],
        'quotation' => ['prefix' => 'QUO', 'year' => true,  'width' => 4],
        'invoice' => ['prefix' => 'INV', 'year' => true,  'width' => 4],
        'payment' => ['prefix' => 'PAY', 'year' => true,  'width' => 4],
        'expense' => ['prefix' => 'EXP', 'year' => true,  'width' => 4],
        'employee' => ['prefix' => 'EMP', 'year' => false, 'width' => 4],
        'service' => ['prefix' => 'SRV', 'year' => false, 'width' => 4],
        'supplier' => ['prefix' => 'SUP', 'year' => false, 'width' => 5],
        'supplier_bill' => ['prefix' => 'BILL', 'year' => true, 'width' => 4],
        'supplier_payment' => ['prefix' => 'SPAY', 'year' => true, 'width' => 4],
        'transfer' => ['prefix' => 'TRF', 'year' => true, 'width' => 4],
        'advance' => ['prefix' => 'ADV', 'year' => true, 'width' => 4],
        'subscription' => ['prefix' => 'SUB', 'year' => true, 'width' => 4],
        'payroll_payment' => ['prefix' => 'SALP', 'year' => true, 'width' => 5],
        'journal' => ['prefix' => 'JRN', 'year' => true,  'width' => 6],
        'leave' => ['prefix' => 'LV',  'year' => true,  'width' => 5],
    ];

    /**
     * Reserve and return the next number for a document type.
     * Uses a row lock inside a transaction to stay safe under concurrency.
     */
    public function next(string $type): string
    {
        $config = $this->config($type);
        $period = $config['year'] ? (int) date('Y') : null;

        return DB::transaction(function () use ($type, $period, $config) {
            $row = DB::table('document_sequences')
                ->where('type', $type)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('document_sequences')->insert([
                    'type' => $type,
                    'period' => $period,
                    'current' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $seq = 1;
            } else {
                $seq = $row->current + 1;
                DB::table('document_sequences')
                    ->where('id', $row->id)
                    ->update(['current' => $seq, 'updated_at' => now()]);
            }

            return $this->format($config, $period, $seq);
        });
    }

    private function format(array $config, ?int $period, int $seq): string
    {
        $parts = [$config['prefix']];

        if ($config['year']) {
            $parts[] = $period;
        }

        $parts[] = str_pad((string) $seq, $config['width'], '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }

    private function config(string $type): array
    {
        return $this->defaults[$type] ?? ['prefix' => strtoupper($type), 'year' => false, 'width' => 5];
    }
}
