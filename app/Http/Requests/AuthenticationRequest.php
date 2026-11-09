<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthenticationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $registering = $this->routeIs('register.store');
        $resetting = $this->routeIs('password.update');

        return [
            'email' => ['required', 'email', 'max:254', Rule::when($registering, Rule::unique('users'))],
            'name' => [Rule::excludeIf(! $registering), 'required', 'string', 'max:100'],
            'password' => [Rule::excludeIf(! ($registering || $resetting || $this->routeIs('login.store'))), 'required', 'string', 'max:128', Rule::when($registering || $resetting, ['confirmed', Password::min(12)])],
            'password_confirmation' => [Rule::excludeIf(! ($registering || $resetting)), 'required', 'string', 'max:128'],
            'token' => [Rule::excludeIf(! $resetting), 'required', 'string', 'max:255'],
        ];
    }
}
