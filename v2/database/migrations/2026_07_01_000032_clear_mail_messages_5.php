<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Очистка писем — пересинк с разбором BODYSTRUCTURE (правильный путь к HTML/plain
// части независимо от глубины вложенности multipart/related, multipart/alternative).
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mail_messages')->delete();
    }

    public function down(): void {}
};
