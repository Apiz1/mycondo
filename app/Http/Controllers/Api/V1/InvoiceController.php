<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Enums\UserRole;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Invoice::with('unit.unitType')->latest('period')->orderBy('unit_id');

        if ($user->role === UserRole::Resident) {
            $query->whereIn('unit_id', $user->activeUnitIds());
        }

        $query->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));

        return InvoiceResource::collection($query->paginate(15));
    }

    public function show(Invoice $invoice)
    {
        Gate::authorize('view', $invoice);

        return new InvoiceResource($invoice->load(['unit.unitType', 'payments' => fn ($q) => $q->latest()]));
    }
}
