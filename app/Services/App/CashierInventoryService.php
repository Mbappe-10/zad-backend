<?php

namespace App\Services\App;

use App\Models\CashierMaterial;
use App\Models\CashierProduction;
use App\Models\CashierRecipeItem;
use App\Models\Product;
use App\Models\SaleReceipt;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Zad stock. One product catalog for the online store and the cashier;
 * tracked products lose stock on every paid sale (online or direct) and gain it
 * from production runs, which also use up raw materials and packaging.
 */
class CashierInventoryService
{
    public function ready(): bool
    {
        return Product::hasCashierColumns() && Schema::hasTable('cashier_stock_moves');
    }

    /**
     * Is stock live for this store? Only with Cashier Pro. Without it quantities are
     * kept but frozen: nothing is blocked or taken, and products are just on/off.
     */
    public function active(?Store $store): bool
    {
        if (! $store || ! $this->ready()) {
            return false;
        }
        return (bool) (app(CashierZadService::class)->entitlement($store->productiveFamily)['features']['inventory'] ?? false);
    }

    /** Stock change for a tracked product; never below zero. Returns the new balance or null when not tracked. */
    public function moveProduct(int $productId, int $change, string $reason, ?string $reference = null): ?int
    {
        return DB::transaction(function () use ($productId, $change, $reason, $reference): ?int {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            if (! $product || ! $product->track_stock) {
                return null;
            }
            $balance = max(0, (int) $product->stock_quantity + $change);
            $product->forceFill(['stock_quantity' => $balance])->save();
            DB::table('cashier_stock_moves')->insert([
                'store_id' => $product->store_id, 'product_id' => $product->id, 'change' => $change,
                'balance' => $balance, 'reason' => $reason, 'reference' => $reference,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $balance;
        });
    }

    public function moveMaterial(CashierMaterial $material, float $change, string $reason, ?string $reference = null): float
    {
        $balance = round(max(0, $material->quantity + $change), 3);
        $material->forceFill(['quantity' => $balance])->save();
        DB::table('cashier_stock_moves')->insert([
            'store_id' => $material->store_id, 'material_id' => $material->id, 'change' => round($change, 3),
            'balance' => $balance, 'reason' => $reason, 'reference' => $reference,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $balance;
    }

    /** Every paid sale takes its items out of stock, once per receipt. */
    public function applySale(SaleReceipt $receipt): void
    {
        if (! $this->ready() || ! $this->active(Store::query()->find($receipt->store_id))) {
            return;
        }
        try {
            $reason = $receipt->sale_type === SaleReceipt::TYPE_PLATFORM ? 'sale_online' : 'sale_quick';
            if (DB::table('cashier_stock_moves')->where('reference', $receipt->number)->where('reason', $reason)->exists()) {
                return;
            }
            foreach ((array) $receipt->items as $item) {
                if (! empty($item['product_id'])) {
                    $this->moveProduct((int) $item['product_id'], -abs((int) ($item['quantity'] ?? 0)), $reason, $receipt->number);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('cashier stock not updated for receipt', ['receipt' => $receipt->id, 'error' => $e->getMessage()]);
        }
    }

    /** Cost of one product from its recipe and current material prices. */
    public function unitCost(int $productId): float
    {
        return round((float) CashierRecipeItem::query()->where('product_id', $productId)->with('material')->get()
            ->sum(fn (CashierRecipeItem $i) => $i->per_unit * (float) ($i->material?->unit_cost ?? 0)), 3);
    }

    /** Production run: product stock up, materials down by the recipe. */
    public function produce(Store $store, Product $product, int $quantity, ?int $by): CashierProduction
    {
        abort_unless($product->store_id === $store->id, 404);

        return DB::transaction(function () use ($store, $product, $quantity, $by): CashierProduction {
            $items = CashierRecipeItem::query()->where('product_id', $product->id)->get();
            $materials = CashierMaterial::query()->whereIn('id', $items->pluck('material_id'))->where('store_id', $store->id)
                ->lockForUpdate()->get()->keyBy('id');
            $used = [];
            $total = 0.0;
            foreach ($items as $item) {
                $material = $materials->get($item->material_id);
                if (! $material) {
                    continue;
                }
                $amount = round($item->per_unit * $quantity, 3);
                $cost = round($amount * $material->unit_cost, 2);
                $total += $cost;
                $short = $material->quantity < $amount;
                $this->moveMaterial($material, -$amount, 'production', 'product:'.$product->id);
                $used[] = ['material_id' => $material->id, 'name' => $material->name, 'unit' => $material->unit,
                    'amount' => $amount, 'cost' => $cost, 'short' => $short];
            }
            if (! $product->track_stock) {
                $product->forceFill(['track_stock' => true, 'stock_quantity' => 0])->save();
            }
            $this->moveProduct($product->id, $quantity, 'production', 'product:'.$product->id);

            return CashierProduction::query()->create([
                'store_id' => $store->id, 'product_id' => $product->id, 'quantity' => $quantity,
                'unit_cost' => $quantity > 0 ? round($total / $quantity, 3) : 0, 'total_cost' => round($total, 2),
                'materials' => $used, 'created_by' => $by,
            ]);
        });
    }

    /** Everything the inventory screens and reports need. */
    public function snapshot(Store $store): array
    {
        $products = Product::query()->where('store_id', $store->id)->whereNull('deleted_at')
            ->orderBy('name_ar')->get(['id', 'name_ar', 'price', 'images', 'status', 'is_available', 'cashier_only', 'track_stock', 'stock_quantity']);
        $recipes = CashierRecipeItem::query()->whereIn('product_id', $products->pluck('id'))->with('material')->get()->groupBy('product_id');
        $materials = CashierMaterial::query()->where('store_id', $store->id)->orderBy('kind')->orderBy('name')->get();

        return [
            'products' => $products->map(function (Product $p) use ($recipes): array {
                $recipe = $recipes->get($p->id, collect());
                $cost = round((float) $recipe->sum(fn ($i) => $i->per_unit * (float) ($i->material?->unit_cost ?? 0)), 2);

                return [
                    'id' => $p->id,
                    'name' => $p->name_ar,
                    'price' => (float) $p->price,
                    'image' => is_array($p->images) ? ($p->images[0] ?? null) : null,
                    'cashier_only' => (bool) $p->cashier_only,
                    'track_stock' => (bool) $p->track_stock,
                    'stock' => $p->track_stock ? (int) $p->stock_quantity : null,
                    'unit_cost' => $recipe->isEmpty() ? null : $cost,
                    'profit' => $recipe->isEmpty() ? null : round((float) $p->price - $cost, 2),
                    'recipe' => $recipe->map(fn ($i) => [
                        'material_id' => $i->material_id, 'name' => $i->material?->name, 'unit' => $i->material?->unit,
                        'per_unit' => $i->per_unit, 'yield' => $i->per_unit > 0 ? round(1 / $i->per_unit, 2) : null,
                    ])->values(),
                ];
            })->values()->all(),
            'materials' => $materials->map(fn (CashierMaterial $m) => [
                'id' => $m->id, 'name' => $m->name, 'kind' => $m->kind, 'unit' => $m->unit,
                'quantity' => $m->quantity, 'unit_cost' => $m->unit_cost, 'low_alert' => $m->low_alert,
                'value' => round($m->quantity * $m->unit_cost, 2),
                'low' => $m->low_alert !== null && $m->quantity <= $m->low_alert,
            ])->values()->all(),
        ];
    }
}
