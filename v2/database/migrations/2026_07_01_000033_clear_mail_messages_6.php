<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Очистка писем — пересинк с полноценным HTML-телом (для рендера в iframe на
// фронтенде через DOMPurify), вместо урезанного до текста strip_tags().
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mail_messages')->delete();
    }

    public function down(): void {}
};
