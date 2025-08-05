<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Shift;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            ['name' => 'Morning', 'start_time' => '08:00', 'end_time' => '12:00'],
            ['name' => 'Afternoon', 'start_time' => '13:00', 'end_time' => '17:00'],
            ['name' => 'Evening', 'start_time' => '18:00', 'end_time' => '21:00'],
        ];

        foreach ($shifts as $shift) {
            Shift::firstOrCreate(
                ['name' => $shift['name']],
                ['start_time' => $shift['start_time'], 'end_time' => $shift['end_time']]
            );
        }
    }
}

