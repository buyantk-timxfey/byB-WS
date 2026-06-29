<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class BankLine extends Model
{
    protected $guarded = [];
    protected $casts = ["date" => "date"];
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function batch(): BelongsTo { return $this->belongsTo(BankBatch::class, "batch_id"); }
    public function matches(): HasMany { return $this->hasMany(BankMatch::class); }
    public function matchedSum(): float { return (float) $this->matches->sum("amount"); }
}
