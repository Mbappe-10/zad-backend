<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user && (
            (bool) ($user->is_owner ?? false)
            || (method_exists($user, 'can') && $user->can('master_settings.access'))
        );
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'max:30'],
            'settings.*' => ['required', 'array', 'max:200'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->getContent();

        abort_if(strlen($raw) > 524288, 413, 'Settings payload is too large.');
    }
}
