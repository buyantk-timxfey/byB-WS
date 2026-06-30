<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $t) {
            $t->json('folders')->nullable()->after('use_ssl');
        });
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $t) {
            $t->dropColumn('folders');
        });
    }
};
