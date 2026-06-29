<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class MailAccount extends Model
{
    protected $guarded = [];
    protected $hidden = ["password"];
    protected $casts = ["use_ssl" => "bool", "password" => "encrypted"];
    public function messages(): HasMany { return $this->hasMany(MailMessage::class, "account_id"); }
}
