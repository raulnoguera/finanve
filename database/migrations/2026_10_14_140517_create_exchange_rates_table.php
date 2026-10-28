<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->string('source', 20); // oficial | paralelo | manual
            $t->decimal('rate', 14, 4); // Bs por 1 USD
            $t->timestamp('fetched_at');
            $t->timestamps();
            $t->index(['source', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
