<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // App\Enums\AccountType: cash|bank|credit|loan
            $table->boolean('on_budget')->default(true); // true = aktiv (budsjett), false = overvåket
            $table->string('currency', 3)->default('NOK');
            $table->boolean('closed')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
