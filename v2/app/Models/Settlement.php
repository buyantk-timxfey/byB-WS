<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Settlement extends Model
{
    protected $guarded = [];
    protected $casts = ["date" => "date"];
    public function counterparty(): BelongsTo { return $this->belongsTo(Counterparty::class); }
}
