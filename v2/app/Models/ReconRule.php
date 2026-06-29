<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ReconRule extends Model
{
    protected $guarded = [];
    public function article(): BelongsTo { return $this->belongsTo(ExpenseArticle::class, "article_id"); }
}
