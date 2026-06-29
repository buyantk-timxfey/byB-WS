<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Транспорт (одна машина): настройки, заправки, маршруты, мойки, пополнения топл. карты.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_settings', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();                    // марка/госномер
            $t->decimal('tank_liters', 6, 1)->default(60);
            $t->decimal('consumption', 5, 1)->default(8);      // л/100км
            $t->unsignedInteger('odometer')->default(0);
            $t->decimal('fuel_left', 6, 1)->default(0);
            $t->decimal('card_balance', 12, 2)->default(0);
            $t->json('calibration')->nullable();               // калибровка бака
            $t->timestamps();
        });

        Schema::create('fuel_ups', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('azs')->nullable();
            $t->decimal('liters', 7, 2);
            $t->decimal('price', 7, 2);
            $t->decimal('sum', 10, 2);
            $t->unsignedInteger('odometer')->nullable();
            $t->enum('paid_from', ['card', 'cash'])->default('card');
            $t->timestamps();
        });

        Schema::create('vehicle_trips', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('route');
            $t->decimal('km', 7, 1);
            $t->decimal('fuel', 7, 2)->default(0);
            $t->string('goal')->nullable();
            $t->timestamps();
        });

        Schema::create('car_washes', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('place')->nullable();
            $t->string('type')->nullable();
            $t->decimal('sum', 10, 2);
            $t->timestamps();
        });

        Schema::create('fuel_card_topups', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->decimal('sum', 10, 2);
            $t->string('source')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_card_topups');
        Schema::dropIfExists('car_washes');
        Schema::dropIfExists('vehicle_trips');
        Schema::dropIfExists('fuel_ups');
        Schema::dropIfExists('vehicle_settings');
    }
};
