<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tombstones for slettede bankimporter: en (account_id, external_id)-post
        // her betyr «ikke importer denne bank-posten på nytt». Seedes inn i
        // dedup-settet ved synk. Kun bokførte (ikke-reserverte) bankrader legges
        // hit; reserverte churner og slettes med vilje ved hver synk.
        Schema::create('ignored_bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->timestamps();

            $table->unique(['account_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ignored_bank_transactions');
    }
};
