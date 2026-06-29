<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Reminder extends Model { protected $guarded = []; protected $casts = ["due_date" => "date", "done" => "bool"]; }
