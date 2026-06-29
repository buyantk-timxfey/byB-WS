<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AdjustmentItem extends Model
{
    protected $guarded = [];
    public function adjustment(): BelongsTo { return $this->belongsTo(Adjustment::class); }
    public function nomenclature(): BelongsTo { return $this->belongsTo(Nomenclature::class); }
}
