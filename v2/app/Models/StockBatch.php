<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockBatch extends Model
{
    protected $guarded = [];
    protected $casts = ["received_date" => "date"];
    public function nomenclature(): BelongsTo { return $this->belongsTo(Nomenclature::class); }
    public function shipmentItem(): BelongsTo { return $this->belongsTo(ShipmentItem::class); }
    public function value(): float { return (float) $this->qty_left * (float) $this->unit_cost; }
    public function daysOnStock(): int { return (int) $this->received_date->diffInDays(now()); }
}
