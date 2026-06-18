<?php

namespace App\Enums;

enum GoalType: string
{
    case Monthly = 'monthly';                          // Tildel et fast beløp hver måned (rullering ignoreres)
    case TargetBalance = 'target_balance';             // Ha et beløp tilgjengelig hver måned (rullering krediteres, månedens forbruk teller ikke)
    case TargetBalanceByDate = 'target_balance_by_date'; // Spar opp til et beløp innen en dato
}
