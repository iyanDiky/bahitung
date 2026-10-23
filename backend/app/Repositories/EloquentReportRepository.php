<?php

namespace App\Repositories;

use App\Repositories\Contracts\ReportRepository;
use Illuminate\Support\Facades\DB;

class EloquentReportRepository implements ReportRepository
{
    public function cashflow(int $household, string $from, string $to, ?int $wallet): array
    {
        $normalFilter = $wallet === null ? '' : " AND EXISTS (SELECT 1 FROM balance_movements m WHERE m.household_id = o.household_id AND m.operation_id = o.id AND m.wallet_id = ? AND m.entry_kind = 'original')";
        $feeFilter = $wallet === null ? '' : ' AND t.source_wallet_id = ?';
        $bindings = [$household, $from, $to, ...($wallet === null ? [] : [$wallet]), $household, $from, $to, ...($wallet === null ? [] : [$wallet]), $household];

        return array_map(fn (object $row): array => (array) $row, DB::select("WITH entries AS (
            SELECT o.occurred_on, o.type, o.category_id, o.amount, 0.00::numeric AS fee_amount
            FROM operations o WHERE o.household_id = ? AND o.status = 'posted'
            AND o.type IN ('income', 'expense') AND o.occurred_on BETWEEN ? AND ? {$normalFilter}
            UNION ALL
            SELECT o.occurred_on, 'expense' AS type, t.fee_category_id, t.fee_amount, t.fee_amount
            FROM operations o JOIN transfer_details t ON t.household_id = o.household_id AND t.operation_id = o.id
            WHERE o.household_id = ? AND o.status = 'posted' AND o.type = 'transfer'
            AND t.fee_amount > 0 AND o.occurred_on BETWEEN ? AND ? {$feeFilter}
        ) SELECT e.occurred_on, e.type, e.category_id, c.name AS category_name, c.is_archived,
            SUM(e.amount)::text AS amount, SUM(e.fee_amount)::text AS fee_amount, COUNT(*)::integer AS entry_count
        FROM entries e JOIN categories c ON c.id = e.category_id AND c.household_id = ?
        GROUP BY e.occurred_on, e.type, e.category_id, c.name, c.is_archived
        ORDER BY e.occurred_on, e.type, e.category_id", $bindings));
    }
}
