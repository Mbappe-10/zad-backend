<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'customers';

    protected $guarded = ['id'];

    protected $fillable = ['name', 'phone', 'email', 'status'];

    protected function casts(): array
    {
        return [];
    }
}
