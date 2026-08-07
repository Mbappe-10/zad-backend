<?php

namespace App\Services\Product;

use App\Models\Product;

class ProductAutoReviewService
{
    /**
     * مراجعة المنتج آليًا قبل النشر.
     *
     * @return array{
     *     status: string,
     *     approved: bool,
     *     score: int,
     *     reasons: array<int, string>
     * }
     */
    public function review(Product $product): array
    {
        $score = 100;
        $reasons = [];

        /*
         * اسم المنتج
         */
        $name = trim((string) $product->name_ar);

        if ($name === '') {
            $score -= 40;
            $reasons[] = 'اسم المنتج مطلوب.';
        }

        if (mb_strlen($name) < 2) {
            $score -= 20;
            $reasons[] = 'اسم المنتج قصير جدًا.';
        }

        if (mb_strlen($name) > 100) {
            $score -= 15;
            $reasons[] = 'اسم المنتج طويل أكثر من اللازم.';
        }

        /*
         * منع الأسماء العشوائية جدًا.
         */
        if (
            $name !== '' &&
            preg_match('/^[\d\W_]+$/u', $name)
        ) {
            $score -= 30;
            $reasons[] = 'اسم المنتج غير واضح.';
        }

        /*
         * السعر
         */
        $price = (float) $product->price;

        if ($price <= 0) {
            $score -= 40;
            $reasons[] = 'سعر المنتج غير صالح.';
        }

        /*
         * الوصف
         */
        $description = trim(
            (string) ($product->description_ar ?? ''),
        );

        if ($description === '') {
            $score -= 10;
            $reasons[] = 'يفضل إضافة وصف واضح للمنتج.';
        }

        if (
            $description !== '' &&
            mb_strlen($description) < 5
        ) {
            $score -= 10;
            $reasons[] = 'وصف المنتج قصير جدًا.';
        }

        if (mb_strlen($description) > 2000) {
            $score -= 10;
            $reasons[] = 'وصف المنتج طويل أكثر من اللازم.';
        }

        /*
         * الصورة
         */
        $hasImage = is_array($product->images)
            && count($product->images) > 0
            && is_string($product->images[0] ?? null)
            && trim($product->images[0]) !== '';

        if (! $hasImage) {
            $score -= 35;
            $reasons[] = 'صورة المنتج مطلوبة قبل النشر.';
        }

        /*
         * المتجر
         */
        if ((int) $product->store_id <= 0) {
            $score -= 40;
            $reasons[] = 'المنتج غير مرتبط بمتجر صالح.';
        }

        /*
         * مدة التجهيز
         */
        if (
            $product->preparation_minutes !== null &&
            (int) $product->preparation_minutes < 0
        ) {
            $score -= 10;
            $reasons[] = 'مدة التجهيز غير صالحة.';
        }

        /*
         * النتيجة النهائية
         */
        if ($score >= 85 && count($reasons) <= 1) {
            return [
                'status' => 'published',
                'approved' => true,
                'score' => max($score, 0),
                'reasons' => $reasons,
            ];
        }

        if ($score >= 60) {
            return [
                'status' => 'needs_changes',
                'approved' => false,
                'score' => max($score, 0),
                'reasons' => $reasons,
            ];
        }

        return [
            'status' => 'manual_review',
            'approved' => false,
            'score' => max($score, 0),
            'reasons' => $reasons,
        ];
    }
}