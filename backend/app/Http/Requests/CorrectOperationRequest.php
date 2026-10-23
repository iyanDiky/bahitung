<?php

namespace App\Http\Requests;

class CorrectOperationRequest extends VoidOperationRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'replacement' => ['required', 'array:type,amount,occurred_on,note,wallet_id,goal_id,category_id,source_wallet_id,destination_wallet_id,fee_amount,fee_category_id'], ...StoreOperationRequest::payloadRules(is_array($this->input('replacement')) ? $this->input('replacement') : [], 'replacement.', allowOpening: true)];
    }
}
