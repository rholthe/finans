<?php

namespace App\Console\Commands;

use App\Services\Bank\EnableBankingProvider;
use Illuminate\Console\Command;

/**
 * Dumper rå ASPSP-metadata fra Enable Banking, til feilsøking av
 * leverandørspesifikke samtykke-/tilgangskrav. Spesielt nyttig for å se hvilke
 * `required_psu_headers` og hvilken `maximum_consent_validity` en gitt bank
 * krever, når én bank (f.eks. Bulder) gir `ASPSP_ERROR` ved uovervåket synk
 * mens en annen (f.eks. DNB) ikke gjør det.
 */
class DumpAspspMetadata extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bank:aspsp-metadata {--country=NO : Landskode} {--filter= : Vis kun banker hvis navn inneholder denne teksten (case-insensitivt)}';

    /**
     * @var string
     */
    protected $description = 'Dump rå ASPSP-metadata fra Enable Banking (required_psu_headers, maximum_consent_validity m.m.)';

    public function handle(EnableBankingProvider $provider): int
    {
        $country = strtoupper((string) $this->option('country'));
        $filter = $this->option('filter');

        $aspsps = $provider->getInstitutionsRaw($country);

        if ($filter) {
            $needle = mb_strtolower((string) $filter);
            $aspsps = array_values(array_filter(
                $aspsps,
                fn (array $a): bool => str_contains(mb_strtolower((string) ($a['name'] ?? '')), $needle),
            ));
        }

        if ($aspsps === []) {
            $this->warn("Ingen ASPSP-er funnet for {$country}".($filter ? " som matcher «{$filter}»" : '').'.');

            return self::SUCCESS;
        }

        foreach ($aspsps as $aspsp) {
            $this->line('');
            $this->info((string) ($aspsp['name'] ?? '(uten navn)').' — '.($aspsp['country'] ?? $country));
            $this->line(json_encode($aspsp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
