<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Sale extends Model
{
    protected $guarded = [];
    protected $casts = ["date" => "date", "posted_at" => "datetime"];
    public function counterparty(): BelongsTo { return $this->belongsTo(Counterparty::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function sourceShipment(): BelongsTo { return $this->belongsTo(Shipment::class, "source_shipment_id"); }
    public function items(): HasMany { return $this->hasMany(SaleItem::class); }
    public function total(): float { return (float) $this->items->sum(fn ($i) => $i->qty * $i->price); }
    public function cost(): float { return (float) $this->items->sum("cost"); }
    public function profit(): float { return $this->total() - $this->cost(); }
    public function isPosted(): bool { return $this->posted_at !== null; }
}
