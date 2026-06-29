<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Nomenclature extends Model
{
    protected $table = "nomenclature";
    protected $guarded = [];
    public function group(): BelongsTo { return $this->belongsTo(NomenclatureGroup::class, "group_id"); }
    public function batches(): HasMany { return $this->hasMany(StockBatch::class); }
    public function moves(): HasMany { return $this->hasMany(StockMove::class); }
    // Текущий остаток = сумма движений
    public function qty(): float { return (float) $this->moves()->sum("qty"); }
}
