<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\BillplzService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillplzWebhookController extends Controller
{
    public function __invoke(Request $request, BillplzService $billplz)
    {
        $params = $request->post();

        if (! $billplz->verifySignature($params)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        DB::transaction(function () use ($params) {
            $payment = Payment::where('billplz_bill_id', $params['id'] ?? null)
                ->lockForUpdate()
                ->first();

            if (! $payment || $payment->status !== PaymentStatus::Pending) {
                return; // unknown bill or already handled: acknowledge, change nothing
            }

            if (($params['paid'] ?? 'false') !== 'true') {
                return; // not paid yet
            }

            $paidSen = (int) ($params['paid_amount'] ?? 0);
            $expectedSen = (int) round(((float) $payment->amount) * 100);

            if ($paidSen !== $expectedSen) {
                report(new \RuntimeException("Billplz amount mismatch on payment {$payment->id}: paid {$paidSen}, expected {$expectedSen}"));

                return; // leave pending for the committee to look at
            }

            $payment->update([
                'status' => PaymentStatus::Approved,
                'verified_at' => now(),
            ]);

            $payment->invoice()->update(['status' => InvoiceStatus::Paid->value]);
        });

        return response()->json(['status' => 'ok']);
    }
}