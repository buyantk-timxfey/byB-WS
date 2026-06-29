<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BankMatch extends Model
{
    protected $guarded = [];
    public function line(): BelongsTo { return $this->belongsTo(BankLine::class, "bank_line_id"); }
}
