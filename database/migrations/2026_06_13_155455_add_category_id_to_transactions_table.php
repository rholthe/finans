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
            // null = ukategorisert / inntekt (Ready to Assign)
            $table->foreignId('category_id')->nullable()->after('account_id')
                ->constrained()->nullOnDelete();
            $table->index(['category_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropIndex(['category_id', 'date']);
            $table->dropColumn('category_id');
        });
    }
};
