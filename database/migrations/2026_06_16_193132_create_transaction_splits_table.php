<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_splits', function (Blueprint $table) {
            $table->id();
            // Pengeraden ligger fortsatt på transactions; splittene fordeler bare
            // beløpet på flere kategorier (Σ split.amount = transaction.amount).
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2); // signert, samme fortegn som transaksjonen
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->index('transaction_id');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_splits');
    }
};
