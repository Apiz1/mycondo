<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Auth\Access\Response;

class ComplaintPolicy
{
    public function view(User $user, Complaint $complaint): bool
    {
        if (in_array($user->role, [UserRole::Admin, UserRole::Committee], true)) {
            return true;
        }

        return $user->role === UserRole::Resident
            && $user->activeUnitIds()->contains($complaint->unit_id);
    }
}
