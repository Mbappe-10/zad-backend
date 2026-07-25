<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Customer;
use App\Models\DigitalEmployee;
use App\Models\DigitalEmployeeTask;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const REPORTS = [
        'orders' => ['label_ar' => 'الطلبات', 'label_en' => 'Orders', 'model' => Order::class, 'date' => 'created_at'],
        'products' => ['label_ar' => 'المنتجات', 'label_en' => 'Products', 'model' => Product::class, 'date' => 'created_at'],
        'families' => ['label_ar' => 'الأسر المنتجة', 'label_en' => 'Productive Families', 'model' => ProductiveFamily::class, 'date' => 'created_at'],
        'stores' => ['label_ar' => 'المتاجر', 'label_en' => 'Stores', 'model' => Store::class, 'date' => 'created_at'],
        'customers' => ['label_ar' => 'العملاء', 'label_en' => 'Customers', 'model' => Customer::class, 'date' => 'created_at'],
        'drivers' => ['label_ar' => 'المناديب', 'label_en' => 'Drivers', 'model' => Driver::class, 'date' => 'created_at'],
        'cities' => ['label_ar' => 'المدن', 'label_en' => 'Cities', 'model' => City::class, 'date' => 'created_at'],
        'payments' => ['label_ar' => 'المدفوعات', 'label_en' => 'Payments', 'model' => Payment::class, 'date' => 'created_at'],
        'wallets' => ['label_ar' => 'المحافظ', 'label_en' => 'Wallets', 'model' => Wallet::class, 'date' => 'created_at'],
        'transactions' => ['label_ar' => 'المعاملات المالية', 'label_en' => 'Wallet Transactions', 'model' => WalletTransaction::class, 'date' => 'created_at'],
        'digital-employees' => ['label_ar' => 'الموظفون الرقميون', 'label_en' => 'Digital Employees', 'model' => DigitalEmployee::class, 'date' => 'created_at'],
        'digital-tasks' => ['label_ar' => 'مهام الموظفين الرقميين', 'label_en' => 'Digital Tasks', 'model' => DigitalEmployeeTask::class, 'date' => 'created_at'],
    ];

    public function catalog(): JsonResponse
    {
        return response()->json([
            'data' => collect(self::REPORTS)->map(fn (array $report, string $key) => [
                'key' => $key,
                'label_ar' => $report['label_ar'],
                'label_en' => $report['label_en'],
            ])->values(),
        ]);
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'data' => [
                'orders' => Order::count(),
                'products' => Product::count(),
                'families' => ProductiveFamily::count(),
                'stores' => Store::count(),
                'customers' => Customer::count(),
                'drivers' => Driver::count(),
                'payments_total' => (float) Payment::sum('amount'),
                'wallet_balance' => (float) Wallet::sum('balance'),
                'digital_employees' => DigitalEmployee::count(),
                'digital_tasks' => DigitalEmployeeTask::count(),
            ],
        ]);
    }

    public function data(Request $request, string $report): JsonResponse
    {
        $definition = $this->definition($report);
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'sort' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $query = $this->query($definition, $validated);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $result = $query->paginate($perPage)->withQueryString();

        return response()->json($result);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        $definition = $this->definition($report);
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $rows = $this->query($definition, $validated)->limit(10000)->get();
        $columns = $this->columns($report, $rows->first()?->toArray() ?? []);
        $filename = sprintf('zad-%s-%s.csv', $report, now()->format('Y-m-d-His'));

        return response()->streamDownload(function () use ($rows, $columns): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_values($columns));

            foreach ($rows as $row) {
                $array = $row->toArray();
                fputcsv($handle, array_map(function (string $key) use ($array) {
                    $value = data_get($array, $key);
                    if (is_array($value) || is_object($value)) {
                        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    return $value;
                }, array_keys($columns)));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function definition(string $report): array
    {
        abort_unless(isset(self::REPORTS[$report]), 404, 'نوع التقرير غير موجود.');
        return self::REPORTS[$report];
    }

    private function query(array $definition, array $filters): Builder
    {
        /** @var Builder $query */
        $query = $definition['model']::query();
        $dateColumn = $definition['date'];

        if (!empty($filters['from'])) {
            $query->whereDate($dateColumn, '>=', Carbon::parse($filters['from'])->toDateString());
        }
        if (!empty($filters['to'])) {
            $query->whereDate($dateColumn, '<=', Carbon::parse($filters['to'])->toDateString());
        }
        if (!empty($filters['search'])) {
            $needle = trim($filters['search']);
            $query->where(function (Builder $sub) use ($needle): void {
                $table = $sub->getModel()->getTable();
                $searchable = collect(['name', 'name_ar', 'name_en', 'title', 'email', 'phone', 'code', 'reference', 'order_number', 'status'])
                    ->filter(fn (string $column) => \Schema::hasColumn($table, $column));
                foreach ($searchable as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $sub->{$method}($column, 'like', "%{$needle}%");
                }
            });
        }

        $table = $query->getModel()->getTable();
        $sort = (string) ($filters['sort'] ?? 'created_at');
        if (!\Schema::hasColumn($table, $sort)) {
            $sort = \Schema::hasColumn($table, 'created_at') ? 'created_at' : 'id';
        }

        return $query->orderBy($sort, $filters['direction'] ?? 'desc');
    }

    private function columns(string $report, array $sample): array
    {
        $preferred = [
            'id' => 'المعرف', 'name' => 'الاسم', 'name_ar' => 'الاسم العربي', 'name_en' => 'الاسم الإنجليزي',
            'code' => 'الرمز', 'email' => 'البريد الإلكتروني', 'phone' => 'رقم الجوال', 'status' => 'الحالة',
            'order_number' => 'رقم الطلب', 'total' => 'الإجمالي', 'amount' => 'المبلغ', 'balance' => 'الرصيد',
            'created_at' => 'تاريخ الإنشاء', 'updated_at' => 'آخر تحديث',
        ];

        $keys = array_values(array_filter(array_keys($preferred), fn (string $key) => array_key_exists($key, $sample)));
        if ($keys === []) {
            $keys = array_slice(array_keys($sample), 0, 20);
        }

        return collect($keys)->mapWithKeys(fn (string $key) => [$key => $preferred[$key] ?? $key])->all();
    }
}
