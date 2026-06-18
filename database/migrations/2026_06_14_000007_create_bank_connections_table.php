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
            $table->string('provider')->default('gocardless'); // hvilken aggregator (GoCardless, Enable Banking)
            $table->string('institution_id');                  // leverandørens institusjons-id
            $table->string('name');
            // Nøytralt samtykke-id (GoCardless «requisition», Enable Banking «session»).
            $table->string('consent_id')->nullable();
            $table->string('status')->default('CR');           // siste kjente samtykke-status
            // Når samtykket utløper (vanligvis 90 dager), og når vi sist varslet
            // brukeren om forestående utløp (nullstilles ved fornying).
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_connections');
    }
};
