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
        Schema::table('transactions', function (Blueprint $table) {
            // «Bevisst plassert i RTA» (inntekt/innskudd, avstemmingsjustering,
            // eller satt av regel/bruker). Endrer ikke RTA-regnestykket – alle
            // ukategoriserte summeres fortsatt – men skiller «vurdert RTA» fra
            // «ikke kategorisert ennå» for varslingen.
            $table->boolean('rta')->default(false)->after('pending');
            $table->index(['account_id', 'category_id', 'rta']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'category_id', 'rta']);
            $table->dropColumn('rta');
        });
    }
};
