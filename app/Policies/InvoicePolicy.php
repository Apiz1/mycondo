<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Auth\Access\Response;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        if (in_array($user->role, [UserRole::Admin, UserRole::Committee], true)) {
            return true;
        }

        return $user->role === UserRole::Resident
            && $user->activeUnitIds()->contains($invoice->unit_id);
    }
}
