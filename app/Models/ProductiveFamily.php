<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductiveFamily extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'productive_families';
    protected $guarded = ['id'];
    protected $fillable = ['code','owner_name','phone','email','health_certificate_number','health_certificate_expires_at','status','city_id','approved_by','approved_at','metadata'];

    protected function casts(): array
    {
        return ['health_certificate_expires_at'=>'date','approved_at'=>'datetime','metadata'=>'array'];
    }
}
