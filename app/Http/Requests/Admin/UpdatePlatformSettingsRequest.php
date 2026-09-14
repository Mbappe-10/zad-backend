<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        $isPlatformOwner = (
            (method_exists($user, 'isPlatformOwner') && $user->isPlatformOwner())
            || (bool) $user->getAttribute('is_platform_owner')
            || (bool) $user->getAttribute('is_owner')
            || (string) $user->getAttribute('role') === 'platform_owner'
        );

        if ($isPlatformOwner) {
            return true;
        }

        return (
            (method_exists($user, 'hasPermission')
                && $user->hasPermission('master_settings.access'))
            || (method_exists($user, 'can')
                && $user->can('master_settings.access'))
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
