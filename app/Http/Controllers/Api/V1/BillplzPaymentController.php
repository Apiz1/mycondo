<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Services\BillplzService;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class BillplzPaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice, BillplzService $billplz)
    {
        Gate::authorize('pay', $invoice);

        try {
            return DB::transaction(function () use ($request, $invoice, $billplz) {
                $invoice = Invoice::with('unit')->whereKey($invoice->id)->lockForUpdate()->first();

                if ($invoice->status === InvoiceStatus::Paid) {
                    return response()->json(['message' => 'This invoice is already paid.'], 409);
                }

                $pending = $invoice->payments()->where('status', PaymentStatus::Pending->value)->first();

                if ($pending) {
                    if ($pending->method === PaymentMethod::Billplz && $pending->billplz_url) {
                        return new PaymentResource($pending);
                    }

                    return response()->json(['message' => 'A payment for this invoice is already awaiting review.'], 409);
                }

                $payment = $invoice->payments()->create([
                    'paid_by' => $request->user()->id,
                    'method' => PaymentMethod::Billplz,
                    'amount' => $invoice->amount,
                    'status' => PaymentStatus::Pending,
                ]);

                $bill = $billplz->createBill($payment, $invoice, $request->user());

                $payment->update([
                    'billplz_bill_id' => $bill['id'],
                    'billplz_url' => $bill['url'],
                ]);
                
                ActivityLog::record('payment.billplz_started', "Online payment started for invoice #{$invoice->id}", $payment);


                return new PaymentResource($payment);
            });
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'The payment gateway is unavailable. Please try again.'], 502);
        }
    }
}