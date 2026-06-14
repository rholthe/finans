<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            // Hvilken aggregator tilkoblingen hører til. Eksisterende rader er
            // GoCardless (default), så de fortsetter å virke uendret.
            $table->string('provider')->default('gocardless')->after('id');
        });

        // Nøytralt navn på samtykke-id-en (GoCardless «requisition», Enable
        // Banking «session»), i tråd med det leverandøruavhengige grensesnittet.
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->renameColumn('requisition_id', 'consent_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->renameColumn('consent_id', 'requisition_id');
        });

        Schema::table('bank_connections', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
