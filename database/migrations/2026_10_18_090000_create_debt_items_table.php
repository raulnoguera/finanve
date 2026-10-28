<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Desglose de una deuda: "Carro $5.000" = enganche + cuotas + seguro…
        Schema::create('debt_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->decimal('amount', 14, 2);
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::table('debt_payments', function (Blueprint $t) {
            // Si el pago saldó un detalle concreto
            $t->foreignId('debt_item_id')->nullable()->after('debt_id')->constrained('debt_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debt_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('debt_item_id'));
        Schema::dropIfExists('debt_items');
    }
};
