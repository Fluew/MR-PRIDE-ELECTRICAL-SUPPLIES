<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->string('category', 100)->nullable();
                $table->boolean('is_partner')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('name');
                $table->index('category');
            });
        }

        // Partnered Brands from http://localhost:8000/products
        $partneredBrands = [
            // Hardware & Machinery
            ['name' => 'DeWalt', 'category' => 'Hardware & Machinery'],
            ['name' => 'Makita', 'category' => 'Hardware & Machinery'],
            ['name' => 'Powerhouse', 'category' => 'Hardware & Machinery'],
            ['name' => 'Stanley', 'category' => 'Hardware & Machinery'],
            ['name' => 'STIHL', 'category' => 'Hardware & Machinery'],
            ['name' => 'Yale', 'category' => 'Hardware & Machinery'],
            ['name' => 'Zekoki', 'category' => 'Hardware & Machinery'],
            ['name' => 'Jr. Kawasaki', 'category' => 'Hardware & Machinery'],
            ['name' => 'Lotus', 'category' => 'Hardware & Machinery'],
            ['name' => 'Yamato', 'category' => 'Hardware & Machinery'],
            ['name' => 'Butterfly', 'category' => 'Hardware & Machinery'],
            ['name' => 'Tacoma', 'category' => 'Hardware & Machinery'],
            ['name' => 'Hoyoma', 'category' => 'Hardware & Machinery'],
            ['name' => 'DCA', 'category' => 'Hardware & Machinery'],
            ['name' => 'Ingco', 'category' => 'Hardware & Machinery'],

            // Electrical Materials
            ['name' => 'Himel', 'category' => 'Electrical Materials'],
            ['name' => 'ROYU', 'category' => 'Electrical Materials'],
            ['name' => 'Panasonic', 'category' => 'Electrical Materials'],
            ['name' => 'Phelps Dodge', 'category' => 'Electrical Materials'],
            ['name' => 'General Electric', 'category' => 'Electrical Materials'],
            ['name' => 'Koten', 'category' => 'Electrical Materials'],
            ['name' => 'Schneider Electric', 'category' => 'Electrical Materials'],
            ['name' => 'Philflex', 'category' => 'Electrical Materials'],
            ['name' => 'Duraflex', 'category' => 'Electrical Materials'],
            ['name' => 'Hypertech', 'category' => 'Electrical Materials'],
            ['name' => 'Pureflex', 'category' => 'Electrical Materials'],
            ['name' => 'Paciflex', 'category' => 'Electrical Materials'],

            // Pipes & Conduits
            ['name' => 'Crown Pipes', 'category' => 'Pipes & Conduits'],
            ['name' => 'Emerald', 'category' => 'Pipes & Conduits'],
            ['name' => 'Neltex', 'category' => 'Pipes & Conduits'],
            ['name' => 'Nichi', 'category' => 'Pipes & Conduits'],
            ['name' => 'McGill', 'category' => 'Pipes & Conduits'],
            ['name' => 'Dupro', 'category' => 'Pipes & Conduits'],
            ['name' => 'Nikon', 'category' => 'Pipes & Conduits'],
            ['name' => 'Atlanta Smartube', 'category' => 'Pipes & Conduits'],

            // Safety Products
            ['name' => '3M', 'category' => 'Safety Products'],
            ['name' => 'Honeywell', 'category' => 'Safety Products'],
            ['name' => 'Ansell', 'category' => 'Safety Products'],
            ['name' => 'MSA Safety', 'category' => 'Safety Products'],
            ['name' => 'Delta Plus', 'category' => 'Safety Products'],
            ['name' => 'CATU Safety', 'category' => 'Safety Products'],
            ['name' => 'Kidde', 'category' => 'Safety Products'],
        ];

        $now = now();
        foreach ($partneredBrands as $brand) {
            DB::table('brands')->updateOrInsert(
                ['name' => $brand['name']],
                [
                    'category' => $brand['category'],
                    'is_partner' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
