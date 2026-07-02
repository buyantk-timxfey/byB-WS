<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Одноразовая чистка банка: удаляет все импортированные строки выписок вместе с их
// привязками и проводками (выручка/расходы/взаиморасчёты кассовым методом), чтобы
// загрузить выписки заново и разнести начисто. Кассовые авто-приходы (auto_sale_id)
// не трогаем — они создаются системой при пробитии чека, и их удаление стёрло бы
// выручку касс. Балансы счетов вернутся к начальным остаткам до повторного импорта.
return new class extends Migration
{
    public function up(): void
    {
        $lineIds = DB::table('bank_lines')->whereNull('auto_sale_id')->pluck('id');
        if ($lineIds->isEmpty()) {
            DB::table('bank_batches')->delete();

            return;
        }

        foreach ($lineIds->chunk(500) as $chunk) {
            DB::table('settlements')->where('doc_type', 'bank_line')->whereIn('doc_id', $chunk)->delete();
            DB::table('turnover')->where('doc_type', 'bank_line')->whereIn('doc_id', $chunk)->delete();
            // bank_matches удалятся каскадом (FK cascadeOnDelete), но SQLite в dev может
            // быть без включённых FK — удаляем явно, это идемпотентно.
            DB::table('bank_matches')->whereIn('bank_line_id', $chunk)->delete();
            DB::table('bank_lines')->whereIn('id', $chunk)->delete();
        }

        DB::table('bank_batches')->delete();
    }

    public function down(): void
    {
        // Чистка данных необратима.
    }
};
