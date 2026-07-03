<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Shipment extends Model
{
    protected $guarded = [];
    protected $casts = ["date" => "date", "eta" => "date", "posted_at" => "datetime", "problem" => "bool"];
    public function counterparty(): BelongsTo { return $this->belongsTo(Counterparty::class); }
    public function carrier(): BelongsTo { return $this->belongsTo(Carrier::class); }
    public function items(): HasMany { return $this->hasMany(ShipmentItem::class); }
    public function etaChanges(): HasMany { return $this->hasMany(ShipmentEtaChange::class); }
    public function receipts(): HasMany { return $this->hasMany(ShipmentReceipt::class); }
    public function goodsTotal(): float { return (float) $this->items->sum(fn ($i) => $i->qty * $i->price); }
    public function total(): float { return $this->goodsTotal() + (float) $this->delivery_cost; }
    public function isPosted(): bool { return $this->posted_at !== null; }
}
