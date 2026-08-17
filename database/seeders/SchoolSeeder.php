<?php

namespace Database\Seeders;

use App\Models\School;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        $schools = [
            [
                'name' => 'Old Sagay Elementary School',
                'school_id_number' => 'SCH-001',
                'address' => 'Brgy. Poblacion, Sagay City',
                'district' => 'Sagay City',
                'division' => 'Negros Occidental',
                'region' => 'Region VI',
                'principal_name' => 'Dr. Maria Santos',
            ],
            [
                'name' => 'Old Sagay Elementary School',
                'school_id_number' => 'SCH-002',
                'address' => 'Brgy. Old Sagay, Sagay City',
                'district' => 'Sagay City',
                'division' => 'Negros Occidental',
                'region' => 'Region VI',
                'principal_name' => 'Mrs. Rosa Dela Cruz',
            ],
            [
                'name' => 'Malubon Elementary School',
                'school_id_number' => 'SCH-003',
                'address' => 'Brgy. Malubon, Sagay City',
                'district' => 'Sagay City',
                'division' => 'Negros Occidental',
                'region' => 'Region VI',
                'principal_name' => 'Mr. Juan Reyes',
            ],
            [
                'name' => 'Sagay National High School',
                'school_id_number' => 'SCH-004',
                'address' => 'Brgy. Poblacion, Sagay City',
                'district' => 'Sagay City',
                'division' => 'Negros Occidental',
                'region' => 'Region VI',
                'principal_name' => 'Mrs. Lourdes Garcia',
            ],
        ];

        foreach ($schools as $school) {
            School::create($school);
        }
    }
}
