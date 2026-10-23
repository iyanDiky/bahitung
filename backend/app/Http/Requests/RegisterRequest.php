<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class RegisterRequest extends FormRequest
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
        return ['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'email.email' => 'Format email tidak valid.', 'email.unique' => 'Email sudah terdaftar.', 'password.min' => 'Kata sandi minimal 12 karakter.', 'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.'];
    }
}
