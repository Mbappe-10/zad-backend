<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isPlatformOwner();
    }

    public function rules(): array
    {
        return [
            'value' => [
                'required',
                'array',
            ],

            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'is_sensitive' => [
                'sometimes',
                'boolean',
            ],

            'confirmation' => [
                'sometimes',
                'boolean',
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
            'value.required' => 'بيانات قسم التحكم مطلوبة.',
            'value.array' => 'يجب إرسال بيانات القسم بصيغة صحيحة.',
            'description.max' => 'وصف القسم طويل جدًا.',
            'reason.max' => 'سبب التعديل طويل جدًا.',
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