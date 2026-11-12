<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\StripeBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function plans(StripeBillingService $billing): JsonResponse
    {
        return response()->json([
            'plans' => $billing->plans(),
            'radii' => [
                'free_km' => config('crime_report.free_radius_km'),
                'paid_km' => config('crime_report.paid_radii_km'),
                'allowed_km' => config('crime_report.allowed_radii_km'),
            ],
        ]);
    }

    public function checkout(Request $request, StripeBillingService $billing): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'in:starter,pro,credits_pack'],
        ]);

        $session = $billing->createCheckoutSession($request->user(), $data['plan']);

        return response()->json([
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ]);
    }

    public function portal(Request $request, StripeBillingService $billing): JsonResponse
    {
        return response()->json([
            'portal_url' => $billing->createPortal($request->user()),
        ]);
    }

    public function webhook(Request $request, StripeBillingService $billing): JsonResponse
    {
        $billing->handleWebhook(
            $request->getContent(),
            $request->header('Stripe-Signature')
        );

        return response()->json(['received' => true]);
    }
}
