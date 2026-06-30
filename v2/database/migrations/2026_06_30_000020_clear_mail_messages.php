<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Удаляет все письма сохранённые старым кодом (без strip_tags <style>).
// После применения — нажать Синхронизировать, письма загрузятся чистыми.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mail_messages')->delete();
    }

    public function down(): void {}
};
