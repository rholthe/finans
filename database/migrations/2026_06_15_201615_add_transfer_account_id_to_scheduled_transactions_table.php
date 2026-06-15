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
        Schema::table('scheduled_transactions', function (Blueprint $table) {
            // Satt = planlagt overføring til denne kontoen (account_id er «fra»).
            // amount lagres da signert fra account_id sitt ståsted (negativt).
            $table->foreignId('transfer_account_id')
                ->nullable()
                ->after('account_id')
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_account_id');
        });
    }
};
