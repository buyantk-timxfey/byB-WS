<?php

namespace App\Services\Posting;

use App\Models\Turnover;
use App\Models\Writeoff;
use App\Services\Fifo;
use Illuminate\Support\Facades\DB;

// Списание (брак/порча): −Остаток по FIFO, +Расход (убыток в P&L).
class WriteoffPosting
{
    public static function post(Writeoff $writeoff): void
    {
        if ($writeoff->posted_at) {
            return;
        }
        $writeoff->load('items');

        DB::transaction(function () use ($writeoff) {
            $totalCost = 0.0;
            foreach ($writeoff->items as $item) {
                if (! $item->nomenclature_id || $item->qty <= 0) {
                    continue;
                }
                $cost = Fifo::consume($item->nomenclature_id, (float) $item->qty, $writeoff->date, 'writeoff', $writeoff->id);
                $item->forceFill(['cost' => $cost])->save();
                $totalCost += $cost;
            }
            if ($totalCost > 0) {
                Turnover::create([
                    'date' => $writeoff->date, 'type' => 'expense', 'amount' => $totalCost,
                    'doc_type' => 'writeoff', 'doc_id' => $writeoff->id,
                ]);
            }
            $writeoff->forceFill(['posted_at' => now()])->save();
        });
    }

    public static function unpost(Writeoff $writeoff): void
    {
        if (! $writeoff->posted_at) {
            return;
        }
        DB::transaction(function () use ($writeoff) {
            Fifo::release('writeoff', $writeoff->id);
            Turnover::where('doc_type', 'writeoff')->where('doc_id', $writeoff->id)->delete();
            $writeoff->items()->update(['cost' => 0]);
            $writeoff->forceFill(['posted_at' => null])->save();
        });
    }
}
