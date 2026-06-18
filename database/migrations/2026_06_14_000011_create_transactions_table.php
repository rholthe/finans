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
            // null = ukategorisert / inntekt (Ready to Assign)
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            // Sporbarhet: hvilken planlagt transaksjon som genererte denne raden.
            $table->foreignId('scheduled_transaction_id')->nullable()->constrained()->nullOnDelete();
            // Parvis overføring: peker på det andre benet. null = vanlig transaksjon.
            $table->foreignId('transfer_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('external_id')->nullable();       // leverandørens id (dedup av bankimport)
            $table->text('bank_description')->nullable();    // rå banktekst (matchegrunnlag for regler)
            $table->foreignId('rule_id')->nullable()->constrained()->nullOnDelete(); // regel som satte payee/memo/kategori
            $table->boolean('locked')->default(false);       // manuelt redigert/beskyttet mot regler
            $table->date('date');
            $table->decimal('amount', 15, 2); // signert: positiv = inn, negativ = ut
            $table->string('payee')->nullable();
            $table->text('memo')->nullable();
            $table->boolean('cleared')->default(false);
            $table->timestamp('reconciled_at')->nullable();  // tidspunkt avstemt (null = ikke avstemt)
            $table->boolean('pending')->default(false);      // reservert (PEND) bankpost; cleared=false følger alltid
            $table->boolean('rta')->default(false);          // bevisst plassert i RTA (inntekt/justering/regel)
            $table->boolean('is_split')->default(false);     // category_id null, beløp fordelt på transaction_splits
            $table->boolean('is_starting_balance')->default(false);
            $table->timestamps();

            $table->index(['account_id', 'date']);
            $table->index(['category_id', 'date']);
            $table->index('external_id');
            $table->index(['account_id', 'pending']);
            $table->index(['account_id', 'category_id', 'rta']);
            $table->index(['account_id', 'is_split']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
