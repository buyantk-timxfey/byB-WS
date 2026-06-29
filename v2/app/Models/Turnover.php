<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Turnover extends Model
{
    protected $table = "turnover";
    protected $guarded = [];
    protected $casts = ["date" => "date"];
    public function article(): BelongsTo { return $this->belongsTo(ExpenseArticle::class, "article_id"); }
}
