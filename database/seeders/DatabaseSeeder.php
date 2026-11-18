<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Residency;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\Facility;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $demoUsers = [
            ['Admin', 'admin@mycondo.test', UserRole::Admin],
            ['Committee Demo', 'committee@mycondo.test', UserRole::Committee],
            ['Resident Demo', 'resident@mycondo.test', UserRole::Resident],
            ['Security Demo', 'security@mycondo.test', UserRole::Security],
        ];

        foreach ($demoUsers as [$name, $email, $role]) {
            User::factory()->create([
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ]);
        }

        $standard = UnitType::create(['name' => 'Standard', 'monthly_fee' => 150.00]);
        $penthouse = UnitType::create(['name' => 'Penthouse', 'monthly_fee' => 300.00]);

        $unit = Unit::create(['unit_type_id' => $standard->id, 'block' => 'A', 'unit_number' => '12-03']);
        Unit::create(['unit_type_id' => $standard->id, 'block' => 'A', 'unit_number' => '12-04']);
        Unit::create(['unit_type_id' => $penthouse->id, 'block' => 'B', 'unit_number' => '20-01']);

        Residency::create([
            'user_id' => User::where('email', 'resident@mycondo.test')->value('id'),
            'unit_id' => $unit->id,
            'relationship' => 'owner',
            'moved_in_at' => now()->subYear(),
        ]);

        Facility::create(['name' => 'Function Hall', 'needs_approval' => true]);
        Facility::create(['name' => 'Badminton Court', 'open_time' => '07:00', 'close_time' => '23:00', 'max_duration_minutes' => 120]);

        Artisan::call('invoices:generate');
    }
}