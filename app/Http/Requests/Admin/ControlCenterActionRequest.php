<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ControlCenterActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isPlatformOwner();
    }

    public function rules(): array
    {
        return [
            'confirmation' => [
                'required',
                'accepted',
            ],

            'reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.required' => 'يجب تأكيد تنفيذ العملية.',
            'confirmation.accepted' => 'لم يتم تأكيد العملية الحساسة.',
            'reason.max' => 'سبب العملية طويل جدًا.',
        ];
    }

    private function isPlatformOwner(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if (
            (bool) $user->getAttribute('is_platform_owner') === true ||
            $user->getAttribute('role') === 'platform_owner'
        ) {
            return true;
        }

        if (
            method_exists($user, 'hasRole') &&
            $user->hasRole('platform_owner')
        ) {
            return true;
        }

        return $user
            ->roles()
            ->where('key', 'platform_owner')
            ->exists();
    }
}