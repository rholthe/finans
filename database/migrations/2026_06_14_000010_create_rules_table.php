<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->boolean('active')->default(true);
            $table->text('match_contains')->nullable();      // komma-/linjeseparerte termer (alle må finnes)
            $table->text('match_not_contains')->nullable();  // termer som ikke må finnes
            $table->string('applies_to')->default('both');   // App\Enums\RuleApplies
            $table->string('set_payee')->nullable();
            $table->text('set_memo')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_type')->default('category'); // App\Enums\RuleTarget: kategori/RTA/overføring
            // Mottakerkonto når target_type = transfer (må være en ikke-synket konto).
            $table->foreignId('transfer_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};
