<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * The launch categories. Safe to run in every environment, repeatedly.
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'music' => 'Music',
            'business' => 'Business',
            'festivals' => 'Festivals',
            'sports' => 'Sports',
            'food-drink' => 'Food & Drink',
            'arts' => 'Arts',
            'technology' => 'Technology',
            'community' => 'Community',
        ];

        $order = 0;

        foreach ($categories as $slug => $name) {
            Category::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'sort_order' => $order++],
            );
        }
    }
}
