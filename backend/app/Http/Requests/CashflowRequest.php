<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class CashflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['from' => ['required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'wallet_id' => ['sometimes', 'integer', 'min:1']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (Carbon::parse($this->input('from'))->diffInDays(Carbon::parse($this->input('to'))) > 365) {
                $validator->errors()->add('to', 'Rentang laporan maksimal 366 hari, termasuk tanggal awal dan akhir.');
            }
        }];
    }
}
