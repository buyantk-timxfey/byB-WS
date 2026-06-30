<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Повторная очистка писем — пересинк с фиксом BODY[1.1] и select() UTF-7.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mail_messages')->delete();
    }

    public function down(): void {}
};
