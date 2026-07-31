<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class AppGuestSession extends Model { use HasUuids; protected $guarded=[]; protected $keyType='string'; public $incrementing=false; protected function casts(): array { return ['permissions'=>'array','last_seen_at'=>'datetime','latitude'=>'decimal:7','longitude'=>'decimal:7']; } }
