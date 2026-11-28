<?php

namespace App\Services\App;

use App\Models\AppProfile;
use App\Models\CashierBranch;
use App\Models\CashierDevice;
use App\Models\CashierMaterial;
use App\Models\CashierPurchase;
use App\Models\CashierPurchaseItem;
use App\Models\CashierSupplier;
use App\Models\CashierSupplierPayment;
use App\Models\CashierTransfer;
use App\Models\Product;
use App\Models\SaleReceipt;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers, purchase invoices, branches and transfers for Cashier Zad Pro.
 *
 * Stock model: a product's stock_quantity and a material's quantity stay the store
 * total (so online sales and production keep working unchanged). Every branch other
 * than the main one keeps its own quantity in cashier_branch_stock; the main branch
 * holds whatever is left. A sale on a device linked to a branch takes from that
 * branch first, and the rest comes from the main branch.
 */
class CashierSupplyService
{
    public const VAT_RATE = 0.15;

    private static ?bool $ready = null;

    public function __construct(private readonly CashierInventoryService $inventory) {}

    public function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('cashier_branches') && Schema::hasTable('cashier_purchases');
    }

    // ---------------------------------------------------------------- branches

    public function mainBranch(Store $store): CashierBranch
    {
        return CashierBranch::query()->firstOrCreate(
            ['store_id' => $store->id, 'is_main' => true],
            ['name' => 'المخزن الرئيسي', 'kind' => 'main', 'active' => true],
        );
    }

    /** @return Collection<int, CashierBranch> */
    public function branches(Store $store): Collection
    {
        $this->mainBranch($store);

        return CashierBranch::query()->where('store_id', $store->id)->orderByDesc('is_main')->orderBy('name')->get();
    }

    public static function productKey(int $id): string
    {
        return 'p:'.$id;
    }

    public static function materialKey(int $id): string
    {
        return 'm:'.$id;
    }

    /** Store total for an item, or null for a product whose stock is not tracked. */
    public function total(Store $store, string $key): ?float
    {
        [$type, $id] = explode(':', $key);
        if ($type === 'p') {
            $p = Product::query()->where('store_id', $store->id)->find((int) $id, ['id', 'track_stock', 'stock_quantity']);

            return $p && $p->track_stock ? (float) $p->stock_quantity : null;
        }
        $m = CashierMaterial::query()->where('store_id', $store->id)->find((int) $id, ['id', 'quantity']);

        return $m ? (float) $m->quantity : null;
    }

    public function quantityAt(CashierBranch $branch, string $key): float
    {
        if (! $branch->is_main) {
            return (float) (DB::table('cashier_branch_stock')->where('branch_id', $branch->id)->where('item_key', $key)->value('quantity') ?? 0);
        }
        $total = $this->total(Store::query()->findOrFail($branch->store_id), $key) ?? 0;
        $elsewhere = (float) DB::table('cashier_branch_stock as s')
            ->join('cashier_branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.store_id', $branch->store_id)->where('b.is_main', false)->where('s.item_key', $key)->sum('s.quantity');

        return round(max(0, $total - $elsewhere), 3);
    }

    /** Change the quantity held by a non-main branch; never below zero. Returns the actual change. */
    private function shiftBranch(CashierBranch $branch, string $key, float $change): float
    {
        if ($branch->is_main || $change == 0) {
            return 0;
        }
        $row = DB::table('cashier_branch_stock')->where('branch_id', $branch->id)->where('item_key', $key)->lockForUpdate()->first();
        $before = (float) ($row->quantity ?? 0);
        $after = round(max(0, $before + $change), 3);
        if ($row) {
            DB::table('cashier_branch_stock')->where('id', $row->id)->update(['quantity' => $after, 'updated_at' => now()]);
        } else {
            DB::table('cashier_branch_stock')->insert([
                'store_id' => $branch->store_id, 'branch_id' => $branch->id, 'item_key' => $key,
                'quantity' => $after, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $after - $before;
    }

    /** Products and materials with the quantity at this branch and its par level. */
    public function branchStock(Store $store, CashierBranch $branch): array
    {
        $rows = DB::table('cashier_branch_stock')->where('branch_id', $branch->id)->get()->keyBy('item_key');
        $others = DB::table('cashier_branch_stock as s')->join('cashier_branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.store_id', $store->id)->where('b.is_main', false)
            ->groupBy('s.item_key')->select('s.item_key', DB::raw('SUM(s.quantity) as q'))->pluck('q', 'item_key');
        $items = [];
        foreach (Product::query()->where('store_id', $store->id)->whereNull('deleted_at')->where('track_stock', true)->orderBy('name_ar')->get(['id', 'name_ar', 'stock_quantity']) as $p) {
            $key = self::productKey($p->id);
            $qty = $branch->is_main ? max(0, (float) $p->stock_quantity - (float) ($others[$key] ?? 0)) : (float) ($rows[$key]->quantity ?? 0);
            $items[] = ['key' => $key, 'type' => 'product', 'id' => $p->id, 'name' => $p->name_ar, 'unit' => 'حبة',
                'quantity' => round($qty, 3), 'total' => (float) $p->stock_quantity,
                'par_level' => isset($rows[$key]) && $rows[$key]->par_level !== null ? (float) $rows[$key]->par_level : null];
        }
        foreach (CashierMaterial::query()->where('store_id', $store->id)->orderBy('name')->get() as $m) {
            $key = self::materialKey($m->id);
            $qty = $branch->is_main ? max(0, $m->quantity - (float) ($others[$key] ?? 0)) : (float) ($rows[$key]->quantity ?? 0);
            $items[] = ['key' => $key, 'type' => 'material', 'id' => $m->id, 'name' => $m->name, 'unit' => $m->unit,
                'quantity' => round($qty, 3), 'total' => $m->quantity,
                'par_level' => isset($rows[$key]) && $rows[$key]->par_level !== null ? (float) $rows[$key]->par_level : null];
        }
        foreach ($items as &$item) {
            $item['below_par'] = $item['par_level'] !== null && $item['quantity'] < $item['par_level'];
        }

        return $items;
    }

    /** @param array<string, float|null> $levels item key => par level (null removes it) */
    public function setParLevels(Store $store, CashierBranch $branch, array $levels): void
    {
        abort_if($branch->is_main, 422, 'الحد الأدنى يُضبط للفروع والبسطات، والمخزن الرئيسي هو اللي يعبّيها.');
        foreach ($levels as $key => $level) {
            abort_unless($this->total($store, $key) !== null, 422, 'صنف غير موجود أو مخزونه غير مفعّل.');
            $exists = DB::table('cashier_branch_stock')->where('branch_id', $branch->id)->where('item_key', $key)->exists();
            if ($exists) {
                DB::table('cashier_branch_stock')->where('branch_id', $branch->id)->where('item_key', $key)
                    ->update(['par_level' => $level, 'updated_at' => now()]);
            } elseif ($level !== null) {
                DB::table('cashier_branch_stock')->insert(['store_id' => $store->id, 'branch_id' => $branch->id, 'item_key' => $key,
                    'quantity' => 0, 'par_level' => $level, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function branchFor(?int $deviceId): ?CashierBranch
    {
        if (! $deviceId || ! Schema::hasColumn('cashier_devices', 'branch_id')) {
            return null;
        }
        $branchId = CashierDevice::query()->whereKey($deviceId)->value('branch_id');

        return $branchId ? CashierBranch::query()->find($branchId) : null;
    }

    /** A sale on a branch device takes from that branch; when it runs below its par level a refill is drafted. */
    public function onSale(SaleReceipt $receipt): void
    {
        if (! $this->ready()) {
            return;
        }
        try {
            $branch = $this->branchFor($receipt->cashier_device_id ?? null);
            if (! $branch || $branch->is_main) {
                return;
            }
            DB::transaction(function () use ($receipt, $branch): void {
                foreach ((array) $receipt->items as $item) {
                    if (! empty($item['product_id'])) {
                        $this->shiftBranch($branch, self::productKey((int) $item['product_id']), -abs((float) ($item['quantity'] ?? 0)));
                    }
                }
            });
            $this->autoRefill(Store::query()->findOrFail($receipt->store_id), $branch);
        } catch (\Throwable $e) {
            Log::warning('branch stock not updated for receipt', ['receipt' => $receipt->id, 'error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------- suppliers

    public function supplierBalances(Store $store): Collection
    {
        $billed = CashierPurchase::query()->where('store_id', $store->id)->where('status', 'received')
            ->groupBy('supplier_id')->select('supplier_id', DB::raw('SUM(total) as billed'), DB::raw('SUM(paid) as paid'),
                DB::raw('COUNT(*) as invoices'), DB::raw('MAX(received_at) as last_at'))->get()->keyBy('supplier_id');
        $overdue = CashierPurchase::query()->where('store_id', $store->id)->where('status', 'received')
            ->whereColumn('paid', '<', 'total')->whereDate('due_date', '<', now('Asia/Riyadh')->toDateString())
            ->groupBy('supplier_id')->select('supplier_id', DB::raw('SUM(total - paid) as due'))->pluck('due', 'supplier_id');

        return CashierSupplier::query()->where('store_id', $store->id)->orderByDesc('active')->orderBy('name')->get()
            ->map(function (CashierSupplier $s) use ($billed, $overdue): array {
                $row = $billed->get($s->id);

                return $this->supplierPayload($s) + [
                    'invoices' => (int) ($row->invoices ?? 0),
                    'purchased' => round((float) ($row->billed ?? 0), 2),
                    'balance' => round((float) ($row->billed ?? 0) - (float) ($row->paid ?? 0), 2),
                    'overdue' => round((float) ($overdue[$s->id] ?? 0), 2),
                    'last_purchase_at' => $row->last_at ?? null,
                ];
            });
    }

    public function supplierPayload(CashierSupplier $s): array
    {
        return $s->only(['id', 'name', 'contact_name', 'phone', 'vat_number', 'payment_days', 'notes', 'active']);
    }

    public function statement(Store $store, CashierSupplier $supplier): array
    {
        $purchases = CashierPurchase::query()->where('store_id', $store->id)->where('supplier_id', $supplier->id)
            ->whereIn('status', ['received', 'draft'])->latest('id')->limit(200)->with('items')->get();
        $payments = CashierSupplierPayment::query()->where('store_id', $store->id)->where('supplier_id', $supplier->id)
            ->latest('paid_at')->limit(200)->get();
        $received = $purchases->where('status', 'received');

        return [
            'supplier' => $this->supplierPayload($supplier),
            'purchased' => round((float) $received->sum('total'), 2),
            'paid' => round((float) $received->sum('paid'), 2),
            'balance' => round((float) $received->sum('total') - (float) $received->sum('paid'), 2),
            'purchases' => $purchases->map(fn (CashierPurchase $p) => $this->purchasePayload($p))->values(),
            'payments' => $payments->map(fn (CashierSupplierPayment $p) => [
                'id' => $p->id, 'amount' => $p->amount, 'method' => $p->method, 'note' => $p->note,
                'purchase_id' => $p->purchase_id, 'paid_at' => $p->paid_at?->toISOString(),
            ])->values(),
        ];
    }

    // ---------------------------------------------------------------- purchases

    /**
     * @param array{supplier_id:int, branch_id?:int|null, items:array<int, array{material_id?:int|null, product_id?:int|null, quantity:float, unit_cost:float}>,
     *   vat_mode?:string, supplier_ref?:string|null, due_date?:string|null, note?:string|null, receive?:bool, paid_now?:float|null, method?:string} $data
     */
    public function createPurchase(Store $store, array $data, ?int $by, bool $auto = false): CashierPurchase
    {
        $supplier = CashierSupplier::query()->where('store_id', $store->id)->findOrFail($data['supplier_id']);
        $branch = isset($data['branch_id']) && $data['branch_id']
            ? CashierBranch::query()->where('store_id', $store->id)->findOrFail($data['branch_id'])
            : null;

        // One transaction: if receiving or the payment fails, nothing is kept.
        return DB::transaction(function () use ($store, $data, $by, $auto, $supplier, $branch): CashierPurchase {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();
            $count = CashierPurchase::query()->where('store_id', $store->id)->count();
            $purchase = CashierPurchase::query()->create([
                'store_id' => $store->id, 'supplier_id' => $supplier->id, 'branch_id' => $branch?->id,
                'number' => 'PO-'.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT),
                'supplier_ref' => $data['supplier_ref'] ?? null, 'status' => 'draft', 'auto' => $auto,
                'due_date' => $data['due_date'] ?? null, 'note' => $data['note'] ?? null, 'created_by' => $by,
            ]);
            $this->fillItems($store, $purchase, $data['items'], (string) ($data['vat_mode'] ?? 'none'));
            if (! empty($data['receive'])) {
                $purchase = $this->receive($store, $purchase);
                if (! empty($data['paid_now']) && $data['paid_now'] > 0) {
                    $this->pay($store, $supplier, (float) $data['paid_now'], (string) ($data['method'] ?? 'cash'), null, $by, $purchase);
                }
            }

            return $purchase->fresh('items');
        });
    }

    public function updateDraft(Store $store, CashierPurchase $purchase, array $data): CashierPurchase
    {
        abort_unless($purchase->status === 'draft', 422, 'الفاتورة مستلمة ولا تتعدل. سجّل فاتورة جديدة أو مرتجع.');

        return DB::transaction(function () use ($store, $purchase, $data): CashierPurchase {
            if (isset($data['supplier_id'])) {
                $purchase->supplier_id = CashierSupplier::query()->where('store_id', $store->id)->findOrFail($data['supplier_id'])->id;
            }
            if (array_key_exists('branch_id', $data)) {
                $purchase->branch_id = $data['branch_id'] ? CashierBranch::query()->where('store_id', $store->id)->findOrFail($data['branch_id'])->id : null;
            }
            $purchase->fill(array_intersect_key($data, array_flip(['supplier_ref', 'due_date', 'note'])))->save();
            if (isset($data['items'])) {
                $purchase->items()->delete();
                $this->fillItems($store, $purchase, $data['items'], (string) ($data['vat_mode'] ?? 'none'));
            }

            return $purchase->fresh('items');
        });
    }

    private function fillItems(Store $store, CashierPurchase $purchase, array $items, string $vatMode): void
    {
        abort_if($items === [], 422, 'أضف صنف واحد على الأقل.');
        $subtotal = 0.0;
        foreach ($items as $item) {
            $qty = round((float) $item['quantity'], 3);
            $cost = round((float) ($item['unit_cost'] ?? 0), 3);
            abort_unless($qty > 0, 422, 'الكمية لازم تكون أكبر من صفر.');
            if (! empty($item['material_id'])) {
                $m = CashierMaterial::query()->where('store_id', $store->id)->findOrFail($item['material_id']);
                $row = ['material_id' => $m->id, 'name' => $m->name, 'unit' => $m->unit];
            } elseif (! empty($item['product_id'])) {
                $p = Product::query()->where('store_id', $store->id)->findOrFail($item['product_id']);
                $row = ['product_id' => $p->id, 'name' => $p->name_ar, 'unit' => 'حبة'];
                abort_unless($qty == floor($qty), 422, 'كمية المنتج لازم تكون عدد صحيح.');
            } else {
                abort(422, 'كل صنف لازم يكون مادة أو منتج.');
            }
            $line = round($qty * $cost, 2);
            $subtotal += $line;
            CashierPurchaseItem::query()->create($row + ['purchase_id' => $purchase->id, 'quantity' => $qty, 'unit_cost' => $cost, 'total' => $line]);
        }
        $subtotal = round($subtotal, 2);
        [$vat, $total] = match ($vatMode) {
            'add' => [round($subtotal * self::VAT_RATE, 2), round($subtotal * (1 + self::VAT_RATE), 2)],
            'included' => [round($subtotal - $subtotal / (1 + self::VAT_RATE), 2), $subtotal],
            default => [0.0, $subtotal],
        };
        $purchase->forceFill(['subtotal' => $subtotal, 'vat' => $vat, 'total' => $total])->save();
    }

    /** Receiving puts every item into stock (at the purchase's branch), averages material costs and sets the due date. */
    public function receive(Store $store, CashierPurchase $purchase): CashierPurchase
    {
        return DB::transaction(function () use ($store, $purchase): CashierPurchase {
            $purchase = CashierPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            abort_unless($purchase->status === 'draft', 422, 'الفاتورة مستلمة من قبل.');
            $branch = $purchase->branch_id ? CashierBranch::query()->find($purchase->branch_id) : null;
            $supplier = CashierSupplier::query()->find($purchase->supplier_id);
            foreach ($purchase->items()->get() as $item) {
                if ($item->material_id) {
                    $m = CashierMaterial::query()->where('store_id', $store->id)->whereKey($item->material_id)->lockForUpdate()->first();
                    if (! $m) {
                        continue;
                    }
                    $held = max(0, $m->quantity);
                    $cost = $held + $item->quantity > 0
                        ? ($held * $m->unit_cost + $item->quantity * $item->unit_cost) / ($held + $item->quantity)
                        : $item->unit_cost;
                    $m->forceFill(['unit_cost' => round($cost, 2)] + (Schema::hasColumn('cashier_materials', 'supplier_id') ? ['supplier_id' => $purchase->supplier_id] : []))->save();
                    $this->inventory->moveMaterial($m, $item->quantity, 'purchase', $purchase->number);
                    if ($branch) {
                        $this->shiftBranch($branch, self::materialKey($m->id), $item->quantity);
                    }
                } elseif ($item->product_id) {
                    $p = Product::query()->where('store_id', $store->id)->find($item->product_id);
                    if (! $p) {
                        continue;
                    }
                    if (! $p->track_stock) {
                        $p->forceFill(['track_stock' => true, 'stock_quantity' => 0])->save();
                    }
                    $this->inventory->moveProduct($p->id, (int) $item->quantity, 'purchase', $purchase->number);
                    if ($branch) {
                        $this->shiftBranch($branch, self::productKey($p->id), $item->quantity);
                    }
                }
            }
            $now = now();
            $purchase->forceFill([
                'status' => 'received', 'received_at' => $now,
                'due_date' => $purchase->due_date ?? $now->copy()->timezone('Asia/Riyadh')->addDays((int) ($supplier?->payment_days ?? 0))->toDateString(),
            ])->save();

            return $purchase->fresh('items');
        });
    }

    public function cancel(CashierPurchase $purchase): CashierPurchase
    {
        abort_unless($purchase->status === 'draft', 422, 'ما تقدر تلغي فاتورة مستلمة.');
        $purchase->forceFill(['status' => 'cancelled'])->save();

        return $purchase;
    }

    /**
     * Pay a supplier. Without a specific invoice the amount settles the oldest unpaid
     * invoices first. Paying more than is owed is refused.
     */
    public function pay(Store $store, CashierSupplier $supplier, float $amount, string $method, ?string $note, ?int $by, ?CashierPurchase $only = null): array
    {
        $amount = round($amount, 2);
        abort_unless($amount > 0, 422, 'المبلغ لازم يكون أكبر من صفر.');

        return DB::transaction(function () use ($store, $supplier, $amount, $method, $note, $by, $only): array {
            $open = CashierPurchase::query()->where('store_id', $store->id)->where('supplier_id', $supplier->id)
                ->where('status', 'received')->whereColumn('paid', '<', 'total')
                ->when($only, fn ($q) => $q->whereKey($only->id))
                ->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();
            $due = round((float) $open->sum(fn ($p) => $p->total - $p->paid), 2);
            abort_if($due <= 0, 422, 'ما عليك شي لهذا المورد.');
            abort_if($amount - $due > 0.009, 422, 'المبلغ أكبر من المستحق ('.number_format($due, 2).' ر.س).');
            $left = $amount;
            $applied = [];
            foreach ($open as $p) {
                if ($left <= 0) {
                    break;
                }
                $part = round(min($left, $p->total - $p->paid), 2);
                $p->forceFill(['paid' => round($p->paid + $part, 2)])->save();
                CashierSupplierPayment::query()->create([
                    'store_id' => $store->id, 'supplier_id' => $supplier->id, 'purchase_id' => $p->id,
                    'amount' => $part, 'method' => $method, 'note' => $note, 'paid_at' => now(), 'created_by' => $by,
                ]);
                $applied[] = ['purchase_id' => $p->id, 'number' => $p->number, 'amount' => $part];
                $left = round($left - $part, 2);
            }

            return ['paid' => $amount, 'applied' => $applied, 'balance' => round($due - $amount, 2)];
        });
    }

    public function purchasePayload(CashierPurchase $p): array
    {
        $today = now('Asia/Riyadh')->toDateString();
        $remaining = round($p->total - $p->paid, 2);

        return [
            'id' => $p->id, 'number' => $p->number, 'supplier_id' => $p->supplier_id,
            'supplier_name' => $p->relationLoaded('supplier') ? $p->supplier?->name : CashierSupplier::query()->whereKey($p->supplier_id)->value('name'),
            'branch_id' => $p->branch_id, 'supplier_ref' => $p->supplier_ref, 'status' => $p->status, 'auto' => $p->auto,
            'subtotal' => $p->subtotal, 'vat' => $p->vat, 'total' => $p->total, 'paid' => $p->paid, 'remaining' => $remaining,
            'payment_status' => $p->status !== 'received' ? null : ($remaining <= 0 ? 'paid' : ($p->paid > 0 ? 'partial' : 'unpaid')),
            'overdue' => $p->status === 'received' && $remaining > 0 && $p->due_date && $p->due_date->toDateString() < $today,
            'due_date' => $p->due_date?->toDateString(), 'note' => $p->note,
            'received_at' => $p->received_at?->toISOString(), 'created_at' => $p->created_at?->toISOString(),
            'items' => $p->items->map(fn (CashierPurchaseItem $i) => $i->only(['id', 'material_id', 'product_id', 'name', 'unit', 'quantity', 'unit_cost', 'total']))->values(),
        ];
    }

    // ---------------------------------------------------------------- transfers

    /** @param array<int, array{key:string, quantity:float}> $items */
    public function createTransfer(Store $store, CashierBranch $from, CashierBranch $to, array $items, ?string $note, ?int $by, bool $complete, bool $auto = false): CashierTransfer
    {
        abort_if($from->id === $to->id, 422, 'اختر فرعين مختلفين.');
        abort_unless($from->store_id === $store->id && $to->store_id === $store->id, 404);
        abort_if($items === [], 422, 'أضف صنف واحد على الأقل.');
        $rows = [];
        foreach ($items as $item) {
            $key = (string) $item['key'];
            abort_unless(preg_match('/^[pm]:\d+$/', $key) === 1, 422, 'صنف غير صحيح.');
            abort_if($this->total($store, $key) === null, 422, 'فعّل تتبع المخزون للمنتج قبل تحويله.');
            $qty = round((float) $item['quantity'], 3);
            abort_unless($qty > 0, 422, 'الكمية لازم تكون أكبر من صفر.');
            $rows[] = ['key' => $key, 'name' => $this->itemName($store, $key), 'unit' => $this->itemUnit($store, $key), 'quantity' => $qty];
        }

        // One transaction: a transfer that cannot be carried out leaves nothing behind.
        return DB::transaction(function () use ($store, $from, $to, $rows, $note, $by, $auto, $complete): CashierTransfer {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();
            $count = CashierTransfer::query()->where('store_id', $store->id)->count();
            $transfer = CashierTransfer::query()->create([
                'store_id' => $store->id, 'number' => 'TR-'.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT),
                'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'status' => 'draft', 'auto' => $auto,
                'items' => $rows, 'note' => $note, 'created_by' => $by,
            ]);

            return $complete ? $this->completeTransfer($store, $transfer) : $transfer;
        });
    }

    /** Move the goods: checks the source still has them, then shifts quantities. Totals do not change. */
    public function completeTransfer(Store $store, CashierTransfer $transfer): CashierTransfer
    {
        return DB::transaction(function () use ($store, $transfer): CashierTransfer {
            $transfer = CashierTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            abort_unless($transfer->status === 'draft', 422, 'التحويل منفّذ أو ملغي.');
            $from = CashierBranch::query()->where('store_id', $store->id)->findOrFail($transfer->from_branch_id);
            $to = CashierBranch::query()->where('store_id', $store->id)->findOrFail($transfer->to_branch_id);
            foreach ($transfer->items as $item) {
                $have = $this->quantityAt($from, $item['key']);
                abort_if($have + 0.0005 < (float) $item['quantity'], 422, "الموجود من {$item['name']} في {$from->name} هو ".rtrim(rtrim(number_format($have, 3, '.', ''), '0'), '.').' فقط.');
            }
            foreach ($transfer->items as $item) {
                $this->shiftBranch($from, $item['key'], -(float) $item['quantity']);
                $this->shiftBranch($to, $item['key'], (float) $item['quantity']);
            }
            $transfer->forceFill(['status' => 'done', 'done_at' => now()])->save();

            return $transfer;
        });
    }

    public function cancelTransfer(CashierTransfer $transfer): CashierTransfer
    {
        abort_unless($transfer->status === 'draft', 422, 'ما تقدر تلغي تحويل منفّذ.');
        $transfer->forceFill(['status' => 'cancelled'])->save();

        return $transfer;
    }

    public function transferPayload(CashierTransfer $t, ?Collection $branches = null): array
    {
        $names = $branches?->pluck('name', 'id') ?? CashierBranch::query()->whereIn('id', [$t->from_branch_id, $t->to_branch_id])->pluck('name', 'id');

        return [
            'id' => $t->id, 'number' => $t->number, 'status' => $t->status, 'auto' => $t->auto,
            'from_branch_id' => $t->from_branch_id, 'from_name' => $names[$t->from_branch_id] ?? null,
            'to_branch_id' => $t->to_branch_id, 'to_name' => $names[$t->to_branch_id] ?? null,
            'items' => $t->items, 'note' => $t->note,
            'done_at' => $t->done_at?->toISOString(), 'created_at' => $t->created_at?->toISOString(),
        ];
    }

    private function itemName(Store $store, string $key): string
    {
        [$type, $id] = explode(':', $key);

        return (string) ($type === 'p'
            ? Product::query()->where('store_id', $store->id)->whereKey((int) $id)->value('name_ar')
            : CashierMaterial::query()->where('store_id', $store->id)->whereKey((int) $id)->value('name'));
    }

    private function itemUnit(Store $store, string $key): string
    {
        [$type, $id] = explode(':', $key);

        return $type === 'p' ? 'حبة' : (string) CashierMaterial::query()->whereKey((int) $id)->value('unit');
    }

    // ---------------------------------------------------------------- automation

    /** Materials at or under their alert level, grouped by the supplier they were last bought from. */
    public function reorderSuggestions(Store $store): array
    {
        $low = CashierMaterial::query()->where('store_id', $store->id)->whereNotNull('low_alert')
            ->whereColumn('quantity', '<=', 'low_alert')->orderBy('name')->get();
        $suppliers = CashierSupplier::query()->where('store_id', $store->id)->pluck('name', 'id');
        $groups = [];
        foreach ($low as $m) {
            $sid = $m->supplier_id ?? null;
            $qty = $m->reorder_quantity !== null && (float) $m->reorder_quantity > 0
                ? (float) $m->reorder_quantity
                : max($m->low_alert * 2 - $m->quantity, $m->low_alert, 1);
            $groups[$sid ?? 0]['supplier_id'] = $sid;
            $groups[$sid ?? 0]['supplier_name'] = $sid ? ($suppliers[$sid] ?? null) : null;
            $groups[$sid ?? 0]['items'][] = [
                'material_id' => $m->id, 'name' => $m->name, 'unit' => $m->unit, 'quantity_now' => $m->quantity,
                'low_alert' => $m->low_alert, 'quantity' => round($qty, 3), 'unit_cost' => $m->unit_cost,
            ];
        }

        return array_values($groups);
    }

    /** Create draft purchase orders for low materials (one per supplier, never twice for the same open need). */
    public function applyReorder(Store $store, ?int $by = null): array
    {
        $created = [];
        foreach ($this->reorderSuggestions($store) as $group) {
            if (! $group['supplier_id']) {
                continue;
            }
            $open = CashierPurchase::query()->where('store_id', $store->id)->where('supplier_id', $group['supplier_id'])
                ->where('status', 'draft')->pluck('id');
            $already = CashierPurchaseItem::query()->whereIn('purchase_id', $open)->pluck('material_id')->filter()->all();
            $items = array_values(array_filter($group['items'], fn ($i) => ! in_array($i['material_id'], $already, false)));
            if ($items === []) {
                continue;
            }
            $created[] = $this->createPurchase($store, [
                'supplier_id' => $group['supplier_id'],
                'items' => array_map(fn ($i) => ['material_id' => $i['material_id'], 'quantity' => $i['quantity'], 'unit_cost' => $i['unit_cost']], $items),
                'note' => 'طلب شراء تلقائي: مواد وصلت حد التنبيه',
            ], $by, true);
        }

        return $created;
    }

    /** Branch items under their par level, with what the main branch can send now. */
    public function refillSuggestions(Store $store, ?CashierBranch $only = null): array
    {
        $main = $this->mainBranch($store);
        $rows = DB::table('cashier_branch_stock as s')->join('cashier_branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.store_id', $store->id)->where('b.is_main', false)->where('b.active', true)
            ->whereNotNull('s.par_level')->whereColumn('s.quantity', '<', 's.par_level')
            ->when($only, fn ($q) => $q->where('b.id', $only->id))
            ->select('s.*', 'b.name as branch_name')->orderBy('b.name')->get();
        $out = [];
        foreach ($rows as $r) {
            if ($this->total($store, $r->item_key) === null) {
                continue;
            }
            $need = round((float) $r->par_level - (float) $r->quantity, 3);
            $available = $this->quantityAt($main, $r->item_key);
            $send = round(min($need, $available), 3);
            if (str_starts_with($r->item_key, 'p:')) {
                $send = floor($send);
            }
            $out[$r->branch_id]['branch_id'] = (int) $r->branch_id;
            $out[$r->branch_id]['branch_name'] = $r->branch_name;
            $out[$r->branch_id]['items'][] = [
                'key' => $r->item_key, 'name' => $this->itemName($store, $r->item_key), 'unit' => $this->itemUnit($store, $r->item_key),
                'quantity_now' => (float) $r->quantity, 'par_level' => (float) $r->par_level,
                'need' => $need, 'available_main' => $available, 'quantity' => $send,
            ];
        }

        return array_values($out);
    }

    /** Draft refill transfers from the main branch (one open draft per branch). */
    public function autoRefill(Store $store, ?CashierBranch $only = null, ?int $by = null): array
    {
        $main = $this->mainBranch($store);
        $created = [];
        foreach ($this->refillSuggestions($store, $only) as $group) {
            $items = array_values(array_filter($group['items'], fn ($i) => $i['quantity'] > 0));
            if ($items === []) {
                continue;
            }
            $open = CashierTransfer::query()->where('store_id', $store->id)->where('to_branch_id', $group['branch_id'])
                ->where('status', 'draft')->where('auto', true)->first();
            if ($open) {
                continue;
            }
            $to = CashierBranch::query()->findOrFail($group['branch_id']);
            $created[] = $transfer = $this->createTransfer($store, $main, $to,
                array_map(fn ($i) => ['key' => $i['key'], 'quantity' => $i['quantity']], $items),
                'تعبئة تلقائية: أصناف نزلت عن الحد الأدنى', $by, false, true);
            $this->notifyOwner($store, 'تعبئة '.$to->name,
                count($items).' صنف نزل عن الحد الأدنى في '.$to->name.'. جهّزنا لك تحويل '.$transfer->number.' من المخزن الرئيسي، اعتمده بضغطة.',
                "refill|{$transfer->id}");
        }

        return $created;
    }

    /** Right after materials are used up: draft the reorder and tell the owner. */
    public function afterMaterialsUsed(Store $store): void
    {
        if (! $this->ready()) {
            return;
        }
        try {
            foreach ($this->applyReorder($store) as $po) {
                $this->notifyOwner($store, 'طلب شراء جاهز',
                    'مواد وصلت حد التنبيه. جهّزنا طلب الشراء '.$po->number.'، راجعه واعتمده.', "reorder|{$po->id}");
            }
        } catch (\Throwable $e) {
            Log::warning('auto reorder failed', ['store' => $store->id, 'error' => $e->getMessage()]);
        }
    }

    /** Daily run: draft reorders and refills, and remind about supplier invoices that are due. */
    public function runDaily(Store $store): array
    {
        $orders = $this->applyReorder($store);
        foreach ($orders as $po) {
            $this->notifyOwner($store, 'طلب شراء جاهز',
                'مواد قربت تخلص. جهّزنا طلب الشراء '.$po->number.' للمورد '.(CashierSupplier::query()->whereKey($po->supplier_id)->value('name') ?? '').'، راجعه واعتمده.',
                "reorder|{$po->id}");
        }
        $refills = $this->autoRefill($store);
        $today = now('Asia/Riyadh');
        $due = CashierPurchase::query()->where('store_id', $store->id)->where('status', 'received')
            ->whereColumn('paid', '<', 'total')->whereDate('due_date', '<=', $today->copy()->addDays(2)->toDateString())->get();
        foreach ($due as $p) {
            $late = $p->due_date->toDateString() < $today->toDateString();
            $this->notifyOwner($store, $late ? 'فاتورة مورد متأخرة' : 'فاتورة مورد قرب موعدها',
                'الفاتورة '.$p->number.' باقي منها '.number_format($p->total - $p->paid, 2).' ر.س، '.($late ? 'تأخر سدادها.' : 'موعدها '.$p->due_date->toDateString().'.'),
                "due|{$p->id}|".$today->toDateString());
        }

        return ['purchases' => count($orders), 'transfers' => count($refills), 'due' => $due->count()];
    }

    public function notifyOwner(Store $store, string $title, string $body, string $key): void
    {
        if (! Schema::hasTable('in_app_notifications')) {
            return;
        }
        $now = now();
        foreach (AppProfile::query()->where('productive_family_id', $store->productive_family_id)->pluck('user_id') as $userId) {
            DB::table('in_app_notifications')->insertOrIgnore([
                'user_id' => $userId, 'title' => $title, 'body' => $body, 'action_url' => '/family/cashier/supply',
                'data' => json_encode(['store_id' => $store->id, 'kind' => 'cashier_supply'], JSON_THROW_ON_ERROR),
                'idempotency_key' => hash('sha256', "supply|{$store->id}|{$key}|{$userId}"),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    // ---------------------------------------------------------------- overview

    public function overview(Store $store): array
    {
        $branches = $this->branches($store);
        $month = now('Asia/Riyadh')->startOfMonth()->utc();
        $received = CashierPurchase::query()->where('store_id', $store->id)->where('status', 'received');
        $balances = $this->supplierBalances($store);
        $deviceCounts = Schema::hasColumn('cashier_devices', 'branch_id')
            ? CashierDevice::query()->where('store_id', $store->id)->whereNull('revoked_at')->whereNotNull('branch_id')
                ->groupBy('branch_id')->select('branch_id', DB::raw('COUNT(*) as n'))->pluck('n', 'branch_id')
            : collect();

        return [
            'branches' => $branches->map(fn (CashierBranch $b) => $b->only(['id', 'name', 'kind', 'address', 'is_main', 'active'])
                + ['devices' => (int) ($deviceCounts[$b->id] ?? 0)])->values(),
            'suppliers' => $balances->values(),
            'totals' => [
                'owed' => round((float) $balances->sum('balance'), 2),
                'overdue' => round((float) $balances->sum('overdue'), 2),
                'purchased_this_month' => round((float) (clone $received)->where('received_at', '>=', $month)->sum('total'), 2),
                'draft_purchases' => CashierPurchase::query()->where('store_id', $store->id)->where('status', 'draft')->count(),
                'draft_transfers' => CashierTransfer::query()->where('store_id', $store->id)->where('status', 'draft')->count(),
            ],
            'reorder' => $this->reorderSuggestions($store),
            'refill' => $this->refillSuggestions($store),
        ];
    }

    public function devices(Store $store): array
    {
        if (! Schema::hasColumn('cashier_devices', 'branch_id')) {
            return [];
        }

        return CashierDevice::query()->where('store_id', $store->id)->whereNull('revoked_at')->where('kind', 'cashier')
            ->orderBy('name')->get(['id', 'name', 'kind', 'branch_id'])->map(fn ($d) => $d->only(['id', 'name', 'kind', 'branch_id']))->all();
    }
}
