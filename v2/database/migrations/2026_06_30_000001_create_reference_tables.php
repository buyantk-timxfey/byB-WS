<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Справочники: группы номенклатуры, контрагенты, номенклатура, перевозчики,
// счета, статьи затрат, настройки, авто-правила сверки.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nomenclature_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('parent_id')->nullable()->constrained('nomenclature_groups')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('counterparties', function (Blueprint $t) {
            $t->id();
            $t->enum('type', ['Поставщик', 'Покупатель', 'Оба'])->default('Оба');
            $t->string('name');
            $t->string('inn', 20)->nullable()->index();   // для авто-сверки по ИНН
            $t->string('kpp', 20)->nullable();
            $t->string('bank_name')->nullable();
            $t->string('bank_account', 40)->nullable();
            $t->string('contact')->nullable();
            $t->text('comment')->nullable();
            $t->timestamps();
        });

        Schema::create('nomenclature', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('group_id')->nullable()->constrained('nomenclature_groups')->nullOnDelete();
            $t->string('unit', 16)->default('шт');         // шт / м / л / упак
            $t->string('article', 64)->nullable()->index();
            $t->text('comment')->nullable();
            $t->timestamps();
        });

        Schema::create('carriers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('site')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->enum('type', ['Банк', 'Касса'])->default('Банк');
            $t->string('bank')->nullable();                // название банка для карточки
            $t->string('last4', 8)->nullable();
            $t->decimal('opening_balance', 14, 2)->default(0);
            $t->string('color', 32)->nullable();           // акцент карточки счёта
            $t->text('comment')->nullable();
            $t->timestamps();
        });

        Schema::create('expense_articles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('is_system')->default(false);      // Эквайринг, Зарплата — нельзя удалить
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        // Авто-правила сверки выписки (подсказки категоризации)
        Schema::create('recon_rules', function (Blueprint $t) {
            $t->id();
            $t->enum('match_field', ['purpose', 'inn'])->default('purpose');
            $t->string('match_value');                     // подстрока назначения или ИНН
            $t->enum('action_type', ['expense_article', 'sale_income', 'shipment_payment', 'acquiring', 'transfer']);
            $t->foreignId('article_id')->nullable()->constrained('expense_articles')->nullOnDelete();
            $t->unsignedInteger('priority')->default(100);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recon_rules');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('expense_articles');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('carriers');
        Schema::dropIfExists('nomenclature');
        Schema::dropIfExists('counterparties');
        Schema::dropIfExists('nomenclature_groups');
    }
};
