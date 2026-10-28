<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('household_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('type', 10); // income | expense
            $t->string('icon', 30)->default('tag');
            $t->string('color', 9)->default('#64748b');
            $t->decimal('monthly_budget', 14, 2)->nullable(); // USD
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
