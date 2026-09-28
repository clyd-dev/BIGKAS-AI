<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'System Administrator',
                'email' => 'admin@bigkasai.com',
                'password' => bcrypt('admin123'),
                'role' => 'admin',
                'school_id' => 1,
                'phone' => '09058831207',
                'is_active' => true,
            ],
            [
                'name' => 'Juan Tamad',
                'email' => 'teacher@bigkasai.com',
                'password' => bcrypt('password'),
                'role' => 'teacher',
                'school_id' => 1,
                'phone' => '09181234567',
                'is_active' => true,
            ],
            [
                'name' => 'Jose Rizal',
                'email' => 'parent@bigkasai.com',
                'password' => bcrypt('password'),
                'role' => 'parent',
                'school_id' => 1,
                'phone' => '09191234567',
                'is_active' => true,
            ],
        ];

        foreach ($users as $user) {
            // role and is_active are guarded — set via explicit assignment.
            $model = User::create([
                'name' => $user['name'],
                'email' => $user['email'],
                'password' => $user['password'],
                'school_id' => $user['school_id'],
                'phone' => $user['phone'],
            ]);
            $model->role = $user['role'];
            $model->is_active = $user['is_active'];
            $model->save();
        }
    }
}
