<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function cancel(User $user, Booking $booking): bool
    {
        if (in_array($user->role, [UserRole::Admin, UserRole::Committee], true)) {
            return true;
        }

        return $user->role === UserRole::Resident && $booking->user_id === $user->id;
    }
}