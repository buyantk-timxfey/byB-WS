<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Документы: поставки, продажи, списания, инвентаризация + табличные части.
// Сквозная нумерация с кодом месяца (ИН/ИЛ…) ведётся через doc_sequences.
return new class extends Migration
{
    public function up(): void
    {
        // Счётчики номеров документов (никогда не обнуляются)
        Schema::create('doc_sequences', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 32)->unique();   // shipment | sale | writeoff | adjustment
            $t->unsignedInteger('last_number')->default(0);
            $t->timestamps();
        });

        // Поставки (поступления)
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->string('number', 32)->unique();
            $t->date('date');
            $t->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $t->string('name')->nullable();
            $t->enum('status', ['Ожидает отправки', 'В пути', 'Завершено'])->default('Ожидает отправки');
            $t->date('eta')->nullable();
            $t->foreignId('carrier_id')->nullable()->constrained('carriers')->nullOnDelete();
            $t->string('tracking', 64)->nullable();
            $t->decimal('delivery_cost', 12, 2)->default(0);   // в себестоимость
            $t->boolean('problem')->default(false);
            $t->text('comment')->nullable();
            $t->timestamp('posted_at')->nullable();            // проведено (оприходовано)
            $t->timestamps();
        });

        Schema::create('shipment_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $t->foreignId('nomenclature_id')->nullable()->constrained('nomenclature')->nullOnDelete();
            $t->decimal('qty', 12, 3);
            $t->decimal('price', 12, 2);                       // цена закупки за единицу
            $t->timestamps();
        });

        // Продажи (реализации)
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->string('number', 32)->unique();
            $t->date('date');
            $t->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $t->string('buyer_name')->nullable();              // разовый покупатель
            $t->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->foreignId('source_shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $t->string('status', 32)->default('Выставлен');    // Выставлен | Оплачен | Отменён
            $t->string('sale_type', 24)->default('Безналичная'); // Касса | Безналичная
            $t->text('comment')->nullable();
            $t->timestamp('posted_at')->nullable();            // отгружено (списание склада)
            $t->timestamps();
        });

        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $t->foreignId('nomenclature_id')->nullable()->constrained('nomenclature')->nullOnDelete();
            $t->decimal('qty', 12, 3);
            $t->decimal('price', 12, 2);                       // цена продажи за единицу
            $t->decimal('cost', 12, 2)->default(0);            // себестоимость FIFO (при проведении)
            $t->timestamps();
        });

        // Списание (брак/порча)
        Schema::create('writeoffs', function (Blueprint $t) {
            $t->id();
            $t->string('number', 32)->unique();
            $t->date('date');
            $t->string('reason')->nullable();
            $t->text('comment')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('writeoff_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('writeoff_id')->constrained('writeoffs')->cascadeOnDelete();
            $t->foreignId('nomenclature_id')->nullable()->constrained('nomenclature')->nullOnDelete();
            $t->decimal('qty', 12, 3);
            $t->decimal('cost', 12, 2)->default(0);            // FIFO
            $t->timestamps();
        });

        // Инвентаризация (корректировка остатков)
        Schema::create('adjustments', function (Blueprint $t) {
            $t->id();
            $t->string('number', 32)->unique();
            $t->date('date');
            $t->text('comment')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('adjustment_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('adjustment_id')->constrained('adjustments')->cascadeOnDelete();
            $t->foreignId('nomenclature_id')->nullable()->constrained('nomenclature')->nullOnDelete();
            $t->decimal('qty_book', 12, 3)->default(0);        // учётный остаток
            $t->decimal('qty_fact', 12, 3)->default(0);        // фактический
            $t->decimal('diff', 12, 3)->default(0);            // +/-
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustment_items');
        Schema::dropIfExists('adjustments');
        Schema::dropIfExists('writeoff_items');
        Schema::dropIfExists('writeoffs');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('doc_sequences');
    }
};
