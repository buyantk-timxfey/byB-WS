<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Counterparty extends Model
{
    protected $guarded = [];
    public function shipments(): HasMany { return $this->hasMany(Shipment::class); }
    public function sales(): HasMany { return $this->hasMany(Sale::class); }
    public function settlements(): HasMany { return $this->hasMany(Settlement::class); }
    // Сальдо: >0 — нам должны (дебиторка), <0 — мы должны (кредиторка)
    public function balance(): float { return (float) $this->settlements()->sum("amount"); }
}
