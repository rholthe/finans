<?php

namespace App\Enums;

enum AccountType: string
{
    case Cash = 'cash';      // Kontant
    case Bank = 'bank';      // Bankkonto
    case Saving = 'saving';  // Sparekonto
    case Credit = 'credit';  // Kredittkort
    case Loan = 'loan';      // Lån
}
