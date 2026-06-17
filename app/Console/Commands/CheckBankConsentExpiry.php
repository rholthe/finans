<?php

namespace App\Console\Commands;

use App\Mail\ExpiringConsentMail;
use App\Models\BankConnection;
use App\Support\AppSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Varsler (én gang per utløpsvindu) om bankgodkjenninger som snart utløper, slik
 * at brukeren kan fornye før den nattlige synken slutter å virke. Idempotent:
 * `expiry_notified_at` settes ved varsling og nullstilles ved fornying.
 */
class CheckBankConsentExpiry extends Command
{
    protected $signature = 'bank:check-expiry';

    protected $description = 'Varsle på e-post om bankgodkjenninger som snart utløper';

    public function handle(): int
    {
        $threshold = now()->addDays((int) config('gocardless.expiry_warning_days', 7));

        $expiring = BankConnection::query()
            ->whereNotNull('valid_until')
            ->whereNull('expiry_notified_at')
            ->where('valid_until', '<=', $threshold)
            ->where('valid_until', '>', now())
            ->orderBy('valid_until')
            ->get();

        if ($expiring->isEmpty()) {
            $this->info('Ingen bankgodkjenninger nær utløp.');

            return self::SUCCESS;
        }

        $email = AppSettings::reportEmail();

        if (empty($email)) {
            Log::info('Ingen rapport-e-post satt (innstilling/BANK_SYNC_REPORT_EMAIL) – hopper over utløpsvarsel.');

            return self::SUCCESS;
        }

        try {
            Mail::to($email)->send(new ExpiringConsentMail($expiring));
            $expiring->each->update(['expiry_notified_at' => now()]);
            $this->info("Sendte utløpsvarsel for {$expiring->count()} tilkobling(er).");
        } catch (\Throwable $e) {
            Log::error('Kunne ikke sende utløpsvarsel: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
