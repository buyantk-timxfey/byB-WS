<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Частичная приёмка поставок: qty_received у позиций + журнал приёмок с датами.
// У завершённых поставок всё считается принятым.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipment_items', 'qty_received')) {
            Schema::table('shipment_items', function (Blueprint $t) {
                $t->decimal('qty_received', 12, 3)->default(0)->after('qty');
            });
        }

        if (! Schema::hasTable('shipment_receipts')) {
            Schema::create('shipment_receipts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
                $t->foreignId('nomenclature_id')->nullable()->constrained('nomenclature')->nullOnDelete();
                $t->decimal('qty', 12, 3);
                $t->date('date');
                $t->timestamps();
            });
        }

        // Завершённые поставки — принято всё
        $done = DB::table('shipments')->where('status', 'Завершено')->pluck('id');
        if ($done->isNotEmpty()) {
            DB::table('shipment_items')->whereIn('shipment_id', $done)->update(['qty_received' => DB::raw('qty')]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_receipts');
        if (Schema::hasColumn('shipment_items', 'qty_received')) {
            Schema::table('shipment_items', function (Blueprint $t) {
                $t->dropColumn('qty_received');
            });
        }
    }
};
