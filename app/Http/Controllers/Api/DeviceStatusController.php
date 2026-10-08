<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceRestrictionPolicyService;
use Illuminate\Http\Request;

/**
 * GET /api/v1/device-status — عام (من غير auth) ومستثنى من UipDeviceRestrictionMiddleware.
 *
 * الفرونت (DeviceGate) بينادي عليه أول ما المنصة تفتح عشان يعرف لو نوع الجهاز
 * الحالي محظور بالسياسة، فيعرض صفحة "الجهاز ده غير مسموح" بدل ما يفتح المنصة.
 * بيرجّع بس allowed + نوع الجهاز + الرسالة — مش تفاصيل السياسة.
 */
class DeviceStatusController extends Controller
{
    public function __construct(private DeviceRestrictionPolicyService $policy)
    {
    }

    public function show(Request $request)
    {
        $ua      = $request->userAgent();
        $allowed = $this->policy->isAllowed($ua);
        $locale  = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';

        return response()->json([
            'success' => true,
            'message' => $allowed ? 'OK' : $this->policy->blockedMessage($locale),
            'data'    => [
                'allowed'     => $allowed,
                'device_type' => $this->policy->classify($ua),
                'message'     => $allowed ? null : $this->policy->blockedMessage($locale),
            ],
            'meta'    => (object) [],
        ])->header('Cache-Control', 'no-store');
    }
}
