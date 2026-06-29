<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class VehicleSetting extends Model
{
    protected $guarded = [];
    protected $casts = ["calibration" => "array"];
    public function fuelPct(): int { return $this->tank_liters > 0 ? (int) round($this->fuel_left / $this->tank_liters * 100) : 0; }
    public function rangeKm(): int { return $this->consumption > 0 ? (int) round($this->fuel_left / $this->consumption * 100) : 0; }
}
