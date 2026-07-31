<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = ['key','value','group','is_public','description','updated_by'];

    protected function casts(): array
    {
        return ['value'=>'json','is_public'=>'boolean'];
    }
}
