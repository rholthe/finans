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
            // Planlagt postering skal bevisst til RTA (typisk lønn): posteringen
            // får da rta=true og teller ikke som «mangler kategori».
            $table->boolean('rta')->default(false)->after('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_transactions', function (Blueprint $table) {
            $table->dropColumn('rta');
        });
    }
};
