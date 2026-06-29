<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Банк (statement-first): батчи импорта выписки, строки выписки, сопоставления.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->decimal('opening_balance', 14, 2)->default(0);
            $t->decimal('closing_balance', 14, 2)->default(0);
            $t->decimal('turn_in', 14, 2)->default(0);
            $t->decimal('turn_out', 14, 2)->default(0);
            $t->unsignedInteger('lines_count')->default(0);
            $t->string('file_hash', 64)->nullable();           // дедуп повторного импорта
            $t->timestamp('imported_at')->nullable();
            $t->timestamps();
        });

        Schema::create('bank_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $t->foreignId('batch_id')->nullable()->constrained('bank_batches')->nullOnDelete();
            $t->date('date');
            $t->decimal('amount', 14, 2);                       // + приход / − расход
            $t->string('counterparty_name')->nullable();
            $t->string('inn', 20)->nullable()->index();
            $t->text('purpose')->nullable();                   // назначение платежа
            $t->string('doc_number', 64)->nullable();
            $t->enum('status', ['unmatched', 'partial', 'matched', 'ignore'])->default('unmatched');
            $t->string('dedup_hash', 64)->nullable()->index(); // № + сумма + дата + счёт
            $t->timestamps();
            $t->index(['account_id', 'date']);
            $t->index(['status']);
        });

        // Множественная/частичная привязка строки к документам/статьям
        Schema::create('bank_matches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_line_id')->constrained('bank_lines')->cascadeOnDelete();
            $t->enum('target_type', ['sale', 'shipment', 'expense_article', 'transfer', 'other']);
            $t->unsignedBigInteger('target_id')->nullable();   // id продажи/поставки/статьи/счёта-пары
            $t->decimal('amount', 14, 2);
            $t->timestamps();
            $t->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_matches');
        Schema::dropIfExists('bank_lines');
        Schema::dropIfExists('bank_batches');
    }
};
