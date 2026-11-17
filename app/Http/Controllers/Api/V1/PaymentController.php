<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;


class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request, Invoice $invoice)
    {
        Gate::authorize('pay', $invoice);

        return DB::transaction(function () use ($request, $invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($invoice->status === InvoiceStatus::Paid) {
                return response()->json(['message' => 'This invoice is already paid.'], 409);
            }

            if ($invoice->payments()->where('status', PaymentStatus::Pending->value)->exists()) {
                return response()->json(['message' => 'A payment for this invoice is already awaiting review.'], 409);
            }

            $payment = $invoice->payments()->create([
                'paid_by' => $request->user()->id,
                'method' => PaymentMethod::Transfer,
                'amount' => $invoice->amount,
                'status' => PaymentStatus::Pending,
                'proof_path' => $request->file('proof')->store('payment-proofs'),
                'note' => $request->validated('note'),
            ]);

            ActivityLog::record('payment.submitted', "Transfer proof submitted for invoice #{$invoice->id}", $payment, ['invoice_id' => $invoice->id]);

            return new PaymentResource($payment);
        });
    }

    public function index(Request $request)
    {
        $payments = Payment::with(['payer', 'invoice.unit.unitType'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(15);

        return PaymentResource::collection($payments);
    }

    public function proof(Payment $payment)
    {
        abort_unless($payment->proof_path && Storage::exists($payment->proof_path), 404);

        return Storage::response($payment->proof_path);
    }

    public function approve(Request $request, Payment $payment)
    {
        return DB::transaction(function () use ($request, $payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($payment->status !== PaymentStatus::Pending) {
                return response()->json(['message' => 'This payment has already been reviewed.'], 409);
            }

            $payment->update([
                'status' => PaymentStatus::Approved,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]);

            $payment->invoice()->update(['status' => InvoiceStatus::Paid->value]);

            ActivityLog::record('payment.approved', "Payment #{$payment->id} approved", $payment, ['invoice_id' => $payment->invoice_id]);

            return new PaymentResource($payment->load(['payer', 'invoice.unit.unitType']));
        });
    }

    public function reject(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'review_note' => ['required', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $payment, $data) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($payment->status !== PaymentStatus::Pending) {
                return response()->json(['message' => 'This payment has already been reviewed.'], 409);
            }

            $payment->update([
                'status' => PaymentStatus::Rejected,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'review_note' => $data['review_note'],
            ]);

            ActivityLog::record('payment.rejected', "Payment #{$payment->id} rejected", $payment, ['reason' => $data['review_note']]);

            return new PaymentResource($payment->load(['payer', 'invoice.unit.unitType']));
        });
    }
}