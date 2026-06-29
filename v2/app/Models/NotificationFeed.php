<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class NotificationFeed extends Model
{
    protected $table = "notifications_feed";
    protected $guarded = [];
    protected $casts = ["read_at" => "datetime"];
}
