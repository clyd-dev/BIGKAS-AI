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
                'division' => 'Sagay City',
                'region' => 'Region VI',
                'principal_name' => 'Dr. Maria Santos',
            ],
        ];

        foreach ($schools as $school) {
            School::create($school);
        }
    }
}
