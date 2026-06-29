<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Account extends Model
{
    protected $guarded = [];
    public function lines(): HasMany { return $this->hasMany(BankLine::class); }
    // Statement-first: баланс = начальный остаток + Σ строк выписки
    public function balance(): float { return (float) $this->opening_balance + (float) $this->lines()->sum("amount"); }
}
