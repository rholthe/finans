<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2); // signert: positiv = inn, negativ = ut
            $table->string('payee')->nullable();
            $table->text('memo')->nullable();
            $table->boolean('cleared')->default(false);
            $table->boolean('is_starting_balance')->default(false);
            $table->timestamps();

            $table->index(['account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
