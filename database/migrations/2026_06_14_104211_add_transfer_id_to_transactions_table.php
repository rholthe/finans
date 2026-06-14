<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Parvis overføring mellom to kontoer: peker på det andre benet.
            // null = vanlig transaksjon. Begge ben slettes sammen i app-laget.
            $table->foreignId('transfer_id')
                ->nullable()
                ->after('scheduled_transaction_id')
                ->constrained('transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_id');
        });
    }
};
