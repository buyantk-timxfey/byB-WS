<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Writeoff extends Model
{
    protected $guarded = [];
    protected $casts = ["date" => "date", "posted_at" => "datetime"];
    public function items(): HasMany { return $this->hasMany(WriteoffItem::class); }
}
