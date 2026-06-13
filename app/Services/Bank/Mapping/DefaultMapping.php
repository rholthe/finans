<?php

namespace App\Services\Bank\Mapping;

class DefaultMapping implements BankMappingInterface
{
    /**
     * Standardfelter i prioritert rekkefølge, slått sammen.
     */
    public function infoString(array $raw): string
    {
        $info = trim(
            ($raw['creditorName'] ?? '').' '.
            ($raw['debtorName'] ?? '').' '.
            ($raw['remittanceInformationUnstructured'] ?? '').' '.
            ($raw['additionalInformation'] ?? '')
        );

        return $info !== '' ? $info : __('Ikke tilgjengelig i API-et');
    }
}
