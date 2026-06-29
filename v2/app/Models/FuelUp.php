<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FuelUp extends Model { protected $guarded = []; protected $casts = ["date" => "date"]; }
