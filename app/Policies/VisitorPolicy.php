<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visitor;
use App\Enums\UserRole;
use Illuminate\Auth\Access\Response;

class VisitorPolicy
{
   public function cancel(User $user, Visitor $visitor): bool
    {
        if (in_array($user->role, [UserRole::Admin, UserRole::Committee], true)) {
            return true;
        }

        return $user->role === UserRole::Resident
            && $user->activeUnitIds()->contains($visitor->unit_id);
    }
}
