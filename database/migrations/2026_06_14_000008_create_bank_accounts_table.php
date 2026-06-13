<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_connection_id')->constrained()->cascadeOnDelete();
            // Kobling til en eksisterende budsjettkonto. Null = ikke koblet ennå
            // (transaksjoner importeres ikke før den er koblet).
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->unique(); // GoCardless konto-id
            $table->string('iban')->nullable();
            $table->boolean('ignored')->default(false);
            $table->unsignedInteger('rate_limit')->nullable();
            $table->unsignedInteger('rate_limit_remaining')->nullable();
            $table->timestamp('rate_limit_reset_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
