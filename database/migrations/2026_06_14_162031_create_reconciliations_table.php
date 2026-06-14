<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->timestamp('reconciled_at');
            $table->decimal('statement_balance', 15, 2); // faktisk banksaldo oppgitt av bruker
            $table->decimal('cleared_balance', 15, 2);   // klarert saldo før justering
            $table->decimal('adjustment_amount', 15, 2)->default(0); // justeringstransaksjonens beløp (0 = ingen)
            $table->timestamps();

            $table->index(['account_id', 'reconciled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
