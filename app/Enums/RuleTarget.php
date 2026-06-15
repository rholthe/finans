<?php

namespace App\Enums;

enum RuleTarget: string
{
    case Category = 'category';  // Sett en konkret kategori
    case Rta = 'rta';            // Marker som «Klar til å fordele» (RTA)
    case Transfer = 'transfer';  // Gjør om til en overføring til en valgt konto
}
