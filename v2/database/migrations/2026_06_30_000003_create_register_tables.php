<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Регистры (накопительные итоги от проведённых документов):
// - stock_batches / stock_moves — партионный склад (FIFO)
// - settlements — взаиморасчёты (дебиторка/кредиторка)
// - turnover — обороты доходов/расходов для P&L (кассовый метод по выручке, COGS по проданному)
// Деньги намеренно отдельной таблицы не имеют: баланс = opening_balance + Σ bank_lines (statement-first).
return new class extends Migration
{
    public function up(): void
    {
        // Партии товара (FIFO). qty_left уменьшается при списании.
        Schema::create('stock_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('nomenclature_id')->constrained('nomenclature')->cascadeOnDelete();
            $t->foreignId('shipment_item_id')->nullable()->constrained('shipment_items')->nullOnDelete();
            $t->date('received_date');
            $t->decimal('qty_in', 12, 3);
            $t->decimal('qty_left', 12, 3);
            $t->decimal('unit_cost', 12, 2);                   // себестоимость единицы (товар+доставка)
            $t->timestamps();
            $t->index(['nomenclature_id', 'received_date']);
        });

        // Движения склада (аудит для карточки товара)
        Schema::create('stock_moves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('nomenclature_id')->constrained('nomenclature')->cascadeOnDelete();
            $t->foreignId('batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $t->date('date');
            $t->decimal('qty', 12, 3);                         // + приход / − расход
            $t->decimal('unit_cost', 12, 2)->default(0);
            $t->string('doc_type', 32);                        // shipment | sale | writeoff | adjustment
            $t->unsignedBigInteger('doc_id');
            $t->timestamps();
            $t->index(['doc_type', 'doc_id']);
        });

        // Взаиморасчёты: amount>0 — контрагент должен нам (дебиторка); <0 — мы должны (кредиторка)
        Schema::create('settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('counterparty_id')->constrained('counterparties')->cascadeOnDelete();
            $t->date('date');
            $t->decimal('amount', 14, 2);
            $t->string('doc_type', 32);                        // sale | shipment | bank_line
            $t->unsignedBigInteger('doc_id');
            $t->string('comment')->nullable();
            $t->timestamps();
            $t->index(['counterparty_id']);
            $t->index(['doc_type', 'doc_id']);
        });

        // Обороты P&L
        Schema::create('turnover', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->enum('type', ['income', 'cogs', 'acquiring', 'expense']);
            $t->foreignId('article_id')->nullable()->constrained('expense_articles')->nullOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('doc_type', 32);                        // sale | bank_line | writeoff
            $t->unsignedBigInteger('doc_id');
            $t->timestamps();
            $t->index(['type', 'date']);
            $t->index(['doc_type', 'doc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turnover');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('stock_moves');
        Schema::dropIfExists('stock_batches');
    }
};
