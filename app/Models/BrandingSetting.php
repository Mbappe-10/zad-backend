<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class BrandingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_name_ar',
        'platform_name_en',
        'company_name_ar',
        'company_name_en',
        'platform_description_ar',
        'platform_description_en',
        'copyright_ar',
        'copyright_en',

        'primary_logo_path',
        'secondary_logo_path',
        'dashboard_logo_path',
        'login_logo_path',
        'favicon_path',

        'login_title_ar',
        'login_title_en',
        'login_description_ar',
        'login_description_en',
        'login_form_title_ar',
        'login_form_title_en',
        'login_form_description_ar',
        'login_form_description_en',
        'login_button_text_ar',
        'login_button_text_en',
        'email_label_ar',
        'email_label_en',
        'password_label_ar',
        'password_label_en',
        'remember_me_text_ar',
        'remember_me_text_en',
        'forgot_password_text_ar',
        'forgot_password_text_en',

        'login_background_path',
        'login_side_image_path',
        'login_background_type',
        'login_background_position',
        'login_background_size',
        'login_overlay_opacity',

        'show_login_logo',
        'show_login_side_panel',
        'show_remember_me',
        'show_forgot_password',
        'show_language_switcher',

        'employee_login_only',
        'guest_login_enabled',
        'registration_enabled',

        'primary_color',
        'secondary_color',
        'login_button_color',
        'login_button_text_color',
        'login_page_background_color',
        'login_card_background_color',
        'login_heading_color',
        'login_text_color',
        'login_input_border_color',
        'login_side_start_color',
        'login_side_end_color',

        'font_family_ar',
        'font_family_en',
        'login_card_radius',
        'login_button_radius',
        'login_card_shadow',

        'default_locale',
        'arabic_enabled',
        'english_enabled',

        'seo_title_ar',
        'seo_title_en',
        'seo_description_ar',
        'seo_description_en',
        'og_image_path',

        'custom_settings',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'login_overlay_opacity' => 'decimal:2',

            'show_login_logo' => 'boolean',
            'show_login_side_panel' => 'boolean',
            'show_remember_me' => 'boolean',
            'show_forgot_password' => 'boolean',
            'show_language_switcher' => 'boolean',

            'employee_login_only' => 'boolean',
            'guest_login_enabled' => 'boolean',
            'registration_enabled' => 'boolean',

            'arabic_enabled' => 'boolean',
            'english_enabled' => 'boolean',

            'custom_settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected $appends = [
        'primary_logo_url',
        'secondary_logo_url',
        'dashboard_logo_url',
        'login_logo_url',
        'favicon_url',
        'login_background_url',
        'login_side_image_url',
        'og_image_url',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(
            BrandingSettingVersion::class,
            'branding_setting_id',
        );
    }

    public function getPrimaryLogoUrlAttribute(): ?string
    {
        return $this->publicFileUrl($this->primary_logo_path);
    }

    public function getSecondaryLogoUrlAttribute(): ?string
    {
        return $this->publicFileUrl($this->secondary_logo_path);
    }

    public function getDashboardLogoUrlAttribute(): ?string
    {
        return $this->publicFileUrl($this->dashboard_logo_path);
    }

    public function getLoginLogoUrlAttribute(): ?string
    {
        return $this->publicFileUrl($this->login_logo_path);
    }

    public function getFaviconUrlAttribute(): ?string
    {
        return $this->publicFileUrl($this->favicon_path);
    }

    public function getLoginBackgroundUrlAttribute(): ?string
    {
        return $this->publicFileUrl($this->login_background_path);
    }

    public function getLoginSideImageUrlAttribute(): ?string
    {
        return $this->publicFileUrl($this->login_side_image_path);
    }

    public function getOgImageUrlAttribute(): ?string
    {
        return $this->publicFileUrl($this->og_image_path);
    }

    private function publicFileUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (
            str_starts_with($path, 'http://') ||
            str_starts_with($path, 'https://') ||
            str_starts_with($path, '/')
        ) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}