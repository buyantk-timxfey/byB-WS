<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentEtaChange extends Model
{
    protected $guarded = [];

    protected $casts = ['old_eta' => 'date', 'new_eta' => 'date'];
}
