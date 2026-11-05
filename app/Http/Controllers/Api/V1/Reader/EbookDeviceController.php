<?php

namespace App\Http\Controllers\Api\V1\Reader;

use App\Http\Controllers\Controller;
use App\Models\EbookDevice;
use App\Services\Ebooks\EbookDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Account → My ebooks → devices: the browsers that read this customer's ebooks. */
class EbookDeviceController extends Controller
{
    public function __construct(private readonly EbookDevices $devices) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();
        $current = $request->header('X-Reader-Device');
        $currentHash = $current ? hash('sha256', $current) : null;
        $rows = EbookDevice::active()->where('customer_id', $customer->id)->orderByDesc('last_used_at')->get()
            ->map(fn (EbookDevice $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'last_used_at' => $d->last_used_at,
                'created_at' => $d->created_at,
                'is_current' => $currentHash !== null && hash_equals($d->device_hash, $currentHash),
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => ['max_devices' => EbookDevices::MAX_DEVICES, 'next_removal_at' => $this->devices->nextRemovalAt($customer)],
        ]);
    }

    public function destroy(Request $request, int $device): JsonResponse
    {
        $this->devices->remove($request->user(), $device);

        return response()->json(null, 204);
    }
}
