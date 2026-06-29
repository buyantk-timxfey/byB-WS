<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ExpenseArticle extends Model
{
    protected $guarded = [];
    protected $casts = ["is_system" => "bool"];
}
