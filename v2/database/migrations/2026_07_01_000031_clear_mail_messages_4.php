<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Очистка писем — пересинк с приоритетом HTML-части (плоские plain-text альтернативы
// у многих отправителей склеены без переносов строк).
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mail_messages')->delete();
    }

    public function down(): void {}
};
