<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Splittet transaksjon: category_id er null og beløpet fordeles på
            // transaction_splits. Flagget skiller en splittet (kategorisert) rad
            // fra en ukategorisert rad, slik at den ikke teller mot RTA/ukategorisert.
            $table->boolean('is_split')->default(false)->after('rta');
            $table->index(['account_id', 'is_split']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'is_split']);
            $table->dropColumn('is_split');
        });
    }
};
