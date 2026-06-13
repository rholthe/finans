<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2); // signert: positiv = inntekt, negativ = utgift
            $table->string('payee')->nullable();
            $table->text('memo')->nullable();
            $table->string('frequency'); // App\Enums\ScheduleFrequency
            $table->date('start_date');
            $table->date('next_date');         // neste forekomst som skal posteres
            $table->date('end_date')->nullable();
            $table->date('last_posted_date')->nullable();
            $table->timestamps();

            $table->index('next_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_transactions');
    }
};
