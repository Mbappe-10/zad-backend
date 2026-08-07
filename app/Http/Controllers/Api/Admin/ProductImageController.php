<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Image\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProductImageController extends Controller
{
    public function __construct(
        private readonly ProductImageService $productImageService,
    ) {
    }

    /**
     * رفع أو استبدال صورة المنتج.
     */
    public function store(
        Request $request,
        Product $product,
    ): JsonResponse {
        $validated = $request->validate([
            'image' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
        ], [
            'image.required' => 'الرجاء اختيار صورة المنتج.',
            'image.image' => 'الملف المرفوع يجب أن يكون صورة صالحة.',
            'image.mimes' => 'الصيغ المسموحة هي JPG وPNG وWebP.',
            'image.max' => 'حجم الصورة الأصلية يجب ألا يتجاوز 10 ميجابايت.',
        ]);

        try {
            $result = $this->productImageService->replace(
                $product,
                $validated['image'],
            );

            return response()->json([
                'message' => 'تم رفع صورة المنتج وتحسينها وضغطها بنجاح.',
                'data' => $result,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * حذف صورة المنتج الحالية.
     */
    public function destroy(
        Product $product,
    ): JsonResponse {
        try {
            $this->productImageService->deleteProductImage(
                $product,
            );

            return response()->json([
                'message' => 'تم حذف صورة المنتج بنجاح.',
                'data' => [
                    'product_id' => $product->id,
                    'image_path' => null,
                    'image_url' => null,
                ],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}