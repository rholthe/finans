<?php

namespace App\Services\Bank\Mapping;

/**
 * Mapping for GoCardless sandbox-banken. Sandbox legger teksten i
 * remittanceInformationUnstructured; ellers oppfører den seg som standard.
 */
class SandboxBankMapping implements BankMappingInterface
{
    public function infoString(array $raw): string
    {
        $info = trim(
            ($raw['remittanceInformationUnstructured'] ?? '').' '.
            ($raw['creditorName'] ?? '')
        );

        return $info !== '' ? $info : __('Sandbox-transaksjon');
    }
}
