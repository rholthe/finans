<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetPassword extends Command
{
    protected $signature = 'app:set-password {password? : Det nye passordet}';

    protected $description = 'Sett appens innloggingspassord (oppdaterer APP_PASSWORD_HASH i .env)';

    public function handle(): int
    {
        $password = $this->argument('password') ?: $this->secret('Nytt passord');

        if (! $password) {
            $this->error('Passord kan ikke være tomt.');

            return self::FAILURE;
        }

        $hash = Hash::make($password);
        $envPath = base_path('.env');

        // Enkle anførselstegn: dotenv behandler innholdet som literal og
        // tolker ikke $-tegnene i bcrypt-hashen som variabler.
        $line = "APP_PASSWORD_HASH='".$hash."'";

        if (! is_writable($envPath)) {
            $this->warn('Kunne ikke skrive til .env. Legg inn denne linjen manuelt:');
            $this->line($line);

            return self::SUCCESS;
        }

        $contents = file_get_contents($envPath);

        if (preg_match('/^APP_PASSWORD_HASH=.*$/m', $contents)) {
            // Callback unngår at $-tegn i hashen tolkes som bakreferanser.
            $contents = preg_replace_callback(
                '/^APP_PASSWORD_HASH=.*$/m',
                fn () => $line,
                $contents
            );
        } else {
            $contents .= PHP_EOL.$line.PHP_EOL;
        }

        file_put_contents($envPath, $contents);
        $this->info('Passord oppdatert.');

        return self::SUCCESS;
    }
}
