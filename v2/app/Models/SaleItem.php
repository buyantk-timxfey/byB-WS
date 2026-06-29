<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SaleItem extends Model
{
    protected $guarded = [];
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function nomenclature(): BelongsTo { return $this->belongsTo(Nomenclature::class); }
    public function sum(): float { return (float) $this->qty * (float) $this->price; }
}
