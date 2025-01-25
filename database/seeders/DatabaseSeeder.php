<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * php artisan migrate:refresh --seed
     *
     * @return void
     */
    public function run()
    {
        $this->call(CommonSeeder::class);
    }
}
