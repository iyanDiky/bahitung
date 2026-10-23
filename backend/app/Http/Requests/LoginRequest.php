<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255'], 'password' => ['required', 'string', 'max:72']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'email.email' => 'Format email tidak valid.', 'email.unique' => 'Email sudah terdaftar.', 'password.min' => 'Kata sandi minimal 12 karakter.', 'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.'];
    }
}
