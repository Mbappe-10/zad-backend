<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\App\CashierStaffService;
use App\Services\App\DeviceLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceLinkController extends Controller
{
    public function __construct(
        private readonly DeviceLinkService $links,
        private readonly CashierStaffService $staff,
    ) {}

    /** Owner: a one-time QR/code for a new device. */
    public function create(Request $request): JsonResponse
    {
        abort_unless($this->staff->ready(), 503, 'الميزة قيد التجهيز.');
        $ctx = $this->staff->resolve($request);
        abort_unless($ctx->isOwner() && $ctx->device === null, 403, 'هذه للمالك فقط.');
        $data = $request->validate([
            'station' => ['required', Rule::in(array_keys(DeviceLinkService::STATIONS))],
            'name' => ['nullable', 'string', 'max:60'],
        ]);
        if ($data['station'] !== 'menu') {
            abort_if($this->staff->ownerRow($ctx->store)->pin_hash === null, 422,
                'حدد رمزك (PIN) أول من صفحة الموظفين، عشان تقدر تفتح الجهاز الجديد.');
        }

        return response()->json(['data' => $this->links->create($ctx->store, $data['station'], (string) ($data['name'] ?? ''), $request->user()->id)], 201);
    }

    public function status(Request $request, string $code): JsonResponse
    {
        $ctx = $this->staff->resolve($request);
        abort_unless($ctx->isOwner() && $ctx->device === null, 403, 'هذه للمالك فقط.');

        return response()->json(['data' => $this->links->status($ctx->store, $code)]);
    }

    /** Device: scan from حسابي → becomes the station. No Zad login needed. */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);
        $linked = $this->links->link($data['code'], $data['device_name'] ?? null);

        return response()->json([
            'message' => 'تم ربط الجهاز: '.DeviceLinkService::STATIONS[$linked['station']].' — '.$linked['store_name'],
            'data' => $linked + ['station_label' => DeviceLinkService::STATIONS[$linked['station']]],
        ], 201);
    }
}
