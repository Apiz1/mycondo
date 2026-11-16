<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
    }
}
