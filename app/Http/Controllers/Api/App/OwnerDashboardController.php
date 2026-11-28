<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\App\CashierStaffService;
use App\Services\App\CashierZadService;
use App\Services\App\OwnerDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Cashier Zad Pro: the owner's live dashboard. */
class OwnerDashboardController extends Controller
{
    public function __invoke(Request $request, CashierStaffService $staff, CashierZadService $cashier, OwnerDashboardService $dashboard): JsonResponse
    {
        $ctx = $staff->resolve($request);
        abort_unless($ctx->can('reports'), 403, 'لوحة المالك لصاحب المتجر أو من عنده صلاحية التقارير.');
        abort_unless((bool) ($cashier->entitlement($ctx->store->productiveFamily)['features']['owner_dashboard'] ?? false), 403,
            'لوحة المالك التفاعلية من مزايا كاشير زاد برو.');

        return response()->json(['data' => $dashboard->build($ctx->store)]);
    }
}
