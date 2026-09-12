<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Category::query()
            ->where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name_ar', 'name_en', 'slug', 'image_path', 'sort_order'])
            ->map(fn (Category $c): array => [
                'id' => $c->id, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en,
                'slug' => $c->slug, 'sort_order' => $c->sort_order,
                'icon_key' => str_starts_with((string) $c->image_path, 'icon:') ? substr($c->image_path, 5) : null,
                'image_url' => $this->mediaUrl($c->image_path),
            ])->values()]);
    }

    public function products(Request $request, Category $category): JsonResponse
    {
        abort_unless($category->is_active, 404);
        $page = Product::query()->where('category_id', $category->id)
            ->where('status', 'active')->where('is_available', true)
            ->whereIn('store_id', Store::query()->select('id')->where('status', 'active')->where('is_open', true))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(min(50, max(1, (int) $request->input('per_page', 20))));
        $stores = Store::query()->whereIn('id', $page->getCollection()->pluck('store_id'))->get()->keyBy('id');
        $data = $page->getCollection()->map(function (Product $p) use ($stores): array {
            $s = $stores->get($p->store_id);
            $images = collect(is_array($p->images) ? $p->images : [])->filter(fn ($v) => is_string($v))->map(fn ($v) => $this->mediaUrl($v))->filter()->values()->all();
            return [
                'id' => $p->id, 'category_id' => $p->category_id, 'store_id' => $p->store_id,
                'name_ar' => $p->name_ar, 'name_en' => $p->name_en,
                'description_ar' => $p->description_ar, 'description_en' => $p->description_en,
                'price' => (float) $p->price,
                'compare_at_price' => $p->compare_at_price === null ? null : (float) $p->compare_at_price,
                'preparation_minutes' => $p->preparation_minutes, 'preparation_mode' => $p->preparation_mode,
                'package_size' => $p->package_size, 'variants' => $p->variants, 'ingredients' => $p->ingredients,
                'images' => $images, 'primary_image_url' => $images[0] ?? null, 'is_available' => true,
                'store_name_ar' => $s?->name_ar, 'store_name_en' => $s?->name_en,
                'store' => ['id' => $s?->id, 'productive_family_id' => $s?->productive_family_id,
                    'name_ar' => $s?->name_ar, 'name_en' => $s?->name_en, 'slug' => $s?->slug,
                    'logo_url' => $this->mediaUrl($s?->logo_path), 'is_open' => true],
            ];
        })->values();
        return response()->json(['data' => $data, 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }

    private function mediaUrl(?string $path): ?string
    {
        if (!$path || str_starts_with($path, 'icon:')) return null;
        if (preg_match('~^https?://~i', $path)) return $path;
        return Storage::disk('public')->url(preg_replace('~^/?storage/~', '', ltrim($path, '/')));
    }
}
