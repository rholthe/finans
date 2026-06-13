<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->string('institution_id'); // GoCardless institusjons-id
            $table->string('name');
            $table->string('requisition_id')->nullable();
            $table->string('status')->default('CR'); // siste kjente requisition-status
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_connections');
    }
};
