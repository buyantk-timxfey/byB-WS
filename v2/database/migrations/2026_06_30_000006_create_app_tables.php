<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Почта (клиент), заметки/напоминания, уведомления, WebAuthn-устройства + PIN.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('email');
            $t->string('imap_host')->nullable();
            $t->unsignedSmallInteger('imap_port')->default(993);
            $t->string('smtp_host')->nullable();
            $t->unsignedSmallInteger('smtp_port')->default(465);
            $t->string('login')->nullable();
            $t->text('password')->nullable();                  // шифруется на уровне модели
            $t->boolean('use_ssl')->default(true);
            $t->timestamps();
        });

        Schema::create('mail_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $t->string('folder', 32)->default('INBOX');
            $t->string('uid')->nullable();
            $t->string('from_name')->nullable();
            $t->string('from_email')->nullable();
            $t->string('subject')->nullable();
            $t->string('preview')->nullable();
            $t->longText('body')->nullable();
            $t->timestamp('date')->nullable();
            $t->boolean('is_read')->default(false);
            $t->boolean('has_attach')->default(false);
            $t->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $t->string('doc_type', 32)->nullable();            // связь с поставкой/продажей
            $t->unsignedBigInteger('doc_id')->nullable();
            $t->timestamps();
            $t->index(['account_id', 'folder']);
        });

        Schema::create('notes', function (Blueprint $t) {
            $t->id();
            $t->text('body');
            $t->boolean('done')->default(false);
            $t->timestamps();
        });

        Schema::create('reminders', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->date('due_date')->nullable();
            $t->boolean('done')->default(false);
            $t->enum('source', ['manual', 'auto'])->default('manual');
            $t->timestamps();
        });

        Schema::create('notifications_feed', function (Blueprint $t) {
            $t->id();
            $t->string('type', 32);                            // debt | eta | bank | reminder
            $t->string('title');
            $t->string('body')->nullable();
            $t->string('link')->nullable();                    // переход к объекту
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        Schema::create('webauthn_credentials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('credential_id', 512);
            $t->text('public_key');
            $t->unsignedBigInteger('counter')->default(0);
            $t->string('name')->nullable();                    // «iPad Pro · Face ID»
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('pin_hash')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('pin_hash');
        });
        Schema::dropIfExists('webauthn_credentials');
        Schema::dropIfExists('notifications_feed');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_accounts');
    }
};
