<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Переносы ETA: любое изменение даты прибытия у поставки пишется в историю,
// задержка считается от первого обещанного срока.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipment_eta_changes')) {
            return;
        }
        Schema::create('shipment_eta_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $t->date('old_eta')->nullable();
            $t->date('new_eta')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_eta_changes');
    }
};
