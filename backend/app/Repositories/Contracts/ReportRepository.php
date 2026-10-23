<?php

namespace App\Repositories\Contracts;

interface ReportRepository
{
    /** @return list<array{occurred_on:string,type:string,category_id:int,category_name:string,is_archived:bool,amount:string,fee_amount:string,entry_count:int}> */
    public function cashflow(int $household, string $from, string $to, ?int $wallet): array;
}
