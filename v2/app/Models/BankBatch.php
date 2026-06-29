<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class BankBatch extends Model
{
    protected $guarded = [];
    protected $casts = ["period_start" => "date", "period_end" => "date", "imported_at" => "datetime"];
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function lines(): HasMany { return $this->hasMany(BankLine::class, "batch_id"); }
}
