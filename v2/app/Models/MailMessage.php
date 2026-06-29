<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class MailMessage extends Model
{
    protected $guarded = [];
    protected $casts = ["date" => "datetime", "is_read" => "bool", "has_attach" => "bool"];
    public function account(): BelongsTo { return $this->belongsTo(MailAccount::class, "account_id"); }
    public function counterparty(): BelongsTo { return $this->belongsTo(Counterparty::class); }
}
