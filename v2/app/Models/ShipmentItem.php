<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ShipmentItem extends Model
{
    protected $guarded = [];
    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function nomenclature(): BelongsTo { return $this->belongsTo(Nomenclature::class); }
    public function sum(): float { return (float) $this->qty * (float) $this->price; }
}
