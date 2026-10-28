<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('household_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 10); // income | expense
            $t->decimal('amount', 14, 2); // siempre en USD
            $t->string('currency', 3)->default('USD'); // moneda en que se registró
            $t->decimal('original_amount', 16, 2); // monto tal cual se ingresó
            $t->decimal('rate', 14, 4)->nullable(); // Bs por USD usado
            $t->date('date')->index();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index(['household_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
