<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forsoner produksjon med det konsoliderte schemaet: `bank_accounts.name`
     * ble lagt til i create-filen, men prod ble aldri migrate:fresh'et, så
     * kolonnen mangler der. Guardet med hasColumn så den er en no-op på ferske
     * installasjoner (der create-filen allerede har den).
     */
    public function up(): void
    {
        if (Schema::hasColumn('bank_accounts', 'name')) {
            return;
        }

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('name')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bank_accounts', 'name')) {
            return;
        }

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
