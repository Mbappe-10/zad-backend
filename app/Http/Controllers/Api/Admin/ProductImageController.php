<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Image\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ProductImageController extends Controller
{
    public function __construct(
        private readonly ProductImageService $productImageService,
    ) {
    }

    /**
     * رفع صورة المنتج مباشرة بدون مراجعة آلية أو معالجة ذكاء اصطناعي.
     */
    public function store(
        Request $request,
        Product $product,
    ): JsonResponse {
        $validated = $request->validate([
            'image' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,gif',
                'max:10240',
            ],
        ], [
            'image.required' => 'الرجاء اختيار صورة المنتج.',
            'image.file' => 'الملف المرفوع غير صالح.',
            'image.mimes' => 'الصيغ المسموحة هي JPG وPNG وWebP وGIF.',
            'image.max' => 'حجم الصورة يجب ألا يتجاوز 10 ميجابايت.',
        ]);

        try {
            $result = $this->productImageService->replace(
                $product,
                $validated['image'],
            );

            return response()->json([
                'message' => 'تم حفظ صورة المنتج بنجاح.',
                'data' => $result,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'تعذر حفظ صورة المنتج. تحقق من مجلد التخزين ثم حاول مرة أخرى.',
            ], 500);
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
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'تعذر حذف صورة المنتج.',
            ], 500);
        }
    }
}