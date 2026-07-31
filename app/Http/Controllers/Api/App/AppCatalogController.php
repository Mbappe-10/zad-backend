<?php
namespace App\Http\Controllers\Api\App;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class AppCatalogController extends Controller {
 public function stores(Request $r): JsonResponse {
  $q=Store::query()->where('status','approved')->when($r->city_id,fn($x,$v)=>$x->where('city_id',$v))->when($r->search,fn($x,$v)=>$x->where(fn($y)=>$y->where('name_ar','like',"%$v%")->orWhere('name_en','like',"%$v%")));
  return response()->json($q->orderByDesc('rating')->paginate(min((int)$r->integer('per_page',20),50)));
 }
 public function store(Store $store): JsonResponse { abort_unless($store->status==='approved',404); return response()->json(['data'=>$store]); }
 public function products(Request $r, Store $store): JsonResponse {
  abort_unless($store->status==='approved',404);
  $q=Product::query()->where('store_id',$store->id)->where('status','published')->where('is_available',true)->when($r->category_id,fn($x,$v)=>$x->where('category_id',$v))->when($r->search,fn($x,$v)=>$x->where(fn($y)=>$y->where('name_ar','like',"%$v%")->orWhere('name_en','like',"%$v%")));
  return response()->json($q->latest()->paginate(min((int)$r->integer('per_page',20),50)));
 }
 public function product(Product $product): JsonResponse { abort_unless($product->status==='published' && $product->is_available,404); return response()->json(['data'=>$product]); }
}
