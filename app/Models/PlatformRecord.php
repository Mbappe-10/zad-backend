<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class PlatformRecord extends Model {
    use SoftDeletes;
    protected $fillable = ['resource','external_key','status','payload','created_by','updated_by'];
    protected function casts(): array { return ['payload'=>'array']; }
}
