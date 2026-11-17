<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Services\BillplzService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillplzWebhookController extends Controller
{
    public function __invoke(Request $request, BillplzService $billplz)
    {
        $params = $request->post();

        if (! $billplz->verifySignature($params)) {
            Log::warning('Billplz callback: invalid signature', ['bill' => $params['id'] ?? null]);

            ActivityLog::record(
                'security.billplz_invalid_signature',
                'Billplz callback with an invalid signature',
                null,
                ['bill_id' => $params['id'] ?? null],
            );

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $paid = ($params['paid'] ?? 'false') === 'true' && ($params['state'] ?? '') === 'paid';

        DB::transaction(function () use ($params, $paid) {
            $payment = Payment::where('billplz_bill_id', $params['id'] ?? null)
                ->lockForUpdate()
                ->first();

            // Unknown bill or already handled: acknowledge so Billplz stops retrying
            if (! $payment || $payment->status !== PaymentStatus::Pending || ! $paid) {
                return;
            }

            $paidSen = (int) ($params['paid_amount'] ?? 0);
            $expectedSen = (int) round(((float) $payment->amount) * 100);

            if ($paidSen !== $expectedSen) {
                Log::error('Billplz callback: amount mismatch', [
                    'payment' => $payment->id,
                    'expected' => $expectedSen,
                    'received' => $paidSen,
                ]);

                ActivityLog::record(
                    'security.amount_mismatch',
                    "Payment amount mismatch for payment #{$payment->id}",
                    $payment,
                    ['expected' => $expectedSen, 'received' => $paidSen],
                );

                return; // leave pending for the committee to look at
            }

            $payment->update([
                'status' => PaymentStatus::Approved,
                'verified_at' => now(),
            ]);

            $payment->invoice()->update(['status' => InvoiceStatus::Paid->value]);

            ActivityLog::record(
                'payment.billplz_paid',
                "Billplz payment received for payment #{$payment->id}",
                $payment,
            );
        });

        return response()->json(['status' => 'ok']);
    }
}