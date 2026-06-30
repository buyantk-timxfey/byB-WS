<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Очистка писем — пересинк с фиксом бинарного контента (PDF/PNG/JPEG в теле).
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mail_messages')->delete();
    }

    public function down(): void {}
};
