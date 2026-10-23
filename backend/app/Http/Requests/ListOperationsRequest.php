<?php

namespace App\Http\Requests;

class ListOperationsRequest extends PageRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'type' => ['sometimes', 'in:opening,income,expense,transfer,goal_contribution,goal_withdrawal,adjustment'], 'status' => ['sometimes', 'in:posted,reversed'], 'sort' => ['sometimes', 'in:asc,desc'], 'from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', ...($this->filled('from') ? ['after_or_equal:from'] : [])], 'goal_id' => ['sometimes', 'integer', 'min:1'], 'wallet_id' => ['sometimes', 'integer', 'min:1'], 'category_id' => ['sometimes', 'integer', 'min:1'], 'created_by_member_id' => ['sometimes', 'integer', 'min:1']];
    }
}
