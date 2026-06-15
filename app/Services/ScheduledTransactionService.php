<?php

namespace App\Services;

use App\Models\ScheduledTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Materialiserer planlagte transaksjoner: når en forekomst forfaller (dato ≤ i
 * dag) opprettes en faktisk transaksjon og next_date avanseres. Tar igjen flere
 * bomte forekomster på én gang, og er idempotent – etter kjøring ligger
 * next_date alltid i framtiden, så gjentatte kall posterer ikke på nytt.
 */
class ScheduledTransactionService
{
    public function __construct(private readonly TransferService $transfers) {}

    /**
     * Poster alle forfalte forekomster t.o.m. $asOf (default i dag).
     *
     * @return int Antall opprettede transaksjoner
     */
    public function postDue(?CarbonImmutable $asOf = null): int
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();

        $due = ScheduledTransaction::query()
            ->whereDate('next_date', '<=', $asOf->toDateString())
            ->get();

        $created = 0;

        foreach ($due as $schedule) {
            $created += DB::transaction(fn (): int => $this->postSchedule($schedule, $asOf));
        }

        return $created;
    }

    private function postSchedule(ScheduledTransaction $schedule, CarbonImmutable $asOf): int
    {
        $created = 0;
        $cursor = CarbonImmutable::parse($schedule->next_date);
        $end = $schedule->end_date ? CarbonImmutable::parse($schedule->end_date) : null;

        while ($cursor->lte($asOf)) {
            if ($end && $cursor->gt($end)) {
                break;
            }

            if ($schedule->isTransfer()) {
                // Planlagt overføring: materialiser begge ben via TransferService
                // (samme budsjett↔overvåket-regler som manuelle overføringer).
                $this->transfers->create(
                    from: $schedule->account,
                    to: $schedule->transferAccount,
                    amount: (float) $schedule->amount,
                    date: $cursor->toDateString(),
                    memo: $schedule->memo,
                    categoryId: $schedule->category_id,
                    scheduledTransactionId: $schedule->id,
                );
            } else {
                $schedule->account->transactions()->create([
                    'scheduled_transaction_id' => $schedule->id,
                    'category_id' => $schedule->category_id,
                    'rta' => $schedule->rta,
                    'date' => $cursor->toDateString(),
                    'amount' => $schedule->amount,
                    'payee' => $schedule->payee,
                    'memo' => $schedule->memo,
                    'cleared' => false,
                    // Låst slik at regelmotoren aldri overskriver en planlagt postering
                    // (samme beskyttelse som manuelt redigerte transaksjoner).
                    'locked' => true,
                ]);
            }

            $schedule->last_posted_date = $cursor->toDateString();
            $cursor = $schedule->frequency->advance($cursor);
            $created++;
        }

        $schedule->next_date = $cursor->toDateString();
        $schedule->save();

        return $created;
    }
}
