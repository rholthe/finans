<?php

namespace App\Enums;

enum GoalType: string
{
    case Monthly = 'monthly';                          // Fyll opp til mål hver måned
    case TargetBalance = 'target_balance';             // Spar opp til et totalbeløp
    case TargetBalanceByDate = 'target_balance_by_date'; // Spar opp til et beløp innen en dato
}
