<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_settings', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | General Platform Identity
            |--------------------------------------------------------------------------
            */

            $table->string('platform_name_ar')->default('زاد');
            $table->string('platform_name_en')->default('ZAD');

            $table->string('company_name_ar')->nullable();
            $table->string('company_name_en')->nullable();

            $table->text('platform_description_ar')->nullable();
            $table->text('platform_description_en')->nullable();

            $table->string('copyright_ar')->nullable();
            $table->string('copyright_en')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Brand Assets
            |--------------------------------------------------------------------------
            */

            $table->string('primary_logo_path')->nullable();
            $table->string('secondary_logo_path')->nullable();
            $table->string('dashboard_logo_path')->nullable();
            $table->string('login_logo_path')->nullable();
            $table->string('favicon_path')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Login Page Content
            |--------------------------------------------------------------------------
            */

            $table->string('login_title_ar')
                ->default('مرحبًا بعودتك');

            $table->string('login_title_en')
                ->default('Welcome Back');

            $table->text('login_description_ar')
                ->nullable();

            $table->text('login_description_en')
                ->nullable();

            $table->string('login_form_title_ar')
                ->default('تسجيل الدخول');

            $table->string('login_form_title_en')
                ->default('Sign In');

            $table->text('login_form_description_ar')
                ->nullable();

            $table->text('login_form_description_en')
                ->nullable();

            $table->string('login_button_text_ar')
                ->default('تسجيل الدخول');

            $table->string('login_button_text_en')
                ->default('Sign In');

            $table->string('email_label_ar')
                ->default('البريد الإلكتروني');

            $table->string('email_label_en')
                ->default('Email Address');

            $table->string('password_label_ar')
                ->default('كلمة المرور');

            $table->string('password_label_en')
                ->default('Password');

            $table->string('remember_me_text_ar')
                ->default('تذكرني');

            $table->string('remember_me_text_en')
                ->default('Remember Me');

            $table->string('forgot_password_text_ar')
                ->default('نسيت كلمة المرور؟');

            $table->string('forgot_password_text_en')
                ->default('Forgot Password?');

            /*
            |--------------------------------------------------------------------------
            | Login Page Media
            |--------------------------------------------------------------------------
            */

            $table->string('login_background_path')->nullable();
            $table->string('login_side_image_path')->nullable();

            $table->enum('login_background_type', [
                'color',
                'gradient',
                'image',
            ])->default('gradient');

            $table->string('login_background_position')
                ->default('center');

            $table->string('login_background_size')
                ->default('cover');

            $table->decimal('login_overlay_opacity', 3, 2)
                ->default(0.00);

            /*
            |--------------------------------------------------------------------------
            | Login Page Visibility
            |--------------------------------------------------------------------------
            */

            $table->boolean('show_login_logo')->default(true);
            $table->boolean('show_login_side_panel')->default(true);
            $table->boolean('show_remember_me')->default(true);
            $table->boolean('show_forgot_password')->default(true);
            $table->boolean('show_language_switcher')->default(true);

            /*
            |--------------------------------------------------------------------------
            | Login Restrictions
            |--------------------------------------------------------------------------
            */

            $table->boolean('employee_login_only')->default(true);
            $table->boolean('guest_login_enabled')->default(false);
            $table->boolean('registration_enabled')->default(false);

            /*
            |--------------------------------------------------------------------------
            | Colors
            |--------------------------------------------------------------------------
            */

            $table->string('primary_color')->default('#EA7A48');
            $table->string('secondary_color')->default('#17324D');

            $table->string('login_button_color')->default('#EA7A48');
            $table->string('login_button_text_color')->default('#FFFFFF');

            $table->string('login_page_background_color')
                ->default('#F8F4EF');

            $table->string('login_card_background_color')
                ->default('#FFFFFF');

            $table->string('login_heading_color')
                ->default('#17324D');

            $table->string('login_text_color')
                ->default('#64748B');

            $table->string('login_input_border_color')
                ->default('#E2E8F0');

            $table->string('login_side_start_color')
                ->default('#F9D2B8');

            $table->string('login_side_end_color')
                ->default('#F3A874');

            /*
            |--------------------------------------------------------------------------
            | Appearance
            |--------------------------------------------------------------------------
            */

            $table->string('font_family_ar')->default('Cairo');
            $table->string('font_family_en')->default('Inter');

            $table->unsignedSmallInteger('login_card_radius')
                ->default(24);

            $table->unsignedSmallInteger('login_button_radius')
                ->default(12);

            $table->string('login_card_shadow')
                ->default('0 24px 60px rgba(15, 23, 42, 0.12)');

            /*
            |--------------------------------------------------------------------------
            | Language
            |--------------------------------------------------------------------------
            */

            $table->string('default_locale', 10)->default('ar');
            $table->boolean('arabic_enabled')->default(true);
            $table->boolean('english_enabled')->default(true);

            /*
            |--------------------------------------------------------------------------
            | SEO
            |--------------------------------------------------------------------------
            */

            $table->string('seo_title_ar')->nullable();
            $table->string('seo_title_en')->nullable();

            $table->text('seo_description_ar')->nullable();
            $table->text('seo_description_en')->nullable();

            $table->string('og_image_path')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Additional Settings
            |--------------------------------------------------------------------------
            */

            $table->json('custom_settings')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Status and Audit
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_settings');
    }
};