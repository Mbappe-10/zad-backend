<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class AutomationRule extends Model {
    protected $fillable = ['digital_employee_id','name','trigger_type','trigger_config','conditions','actions','is_active','last_triggered_at'];
    protected function casts(): array { return ['trigger_config'=>'array','conditions'=>'array','actions'=>'array','is_active'=>'boolean','last_triggered_at'=>'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(DigitalEmployee::class,'digital_employee_id'); }
    public function runs(): HasMany { return $this->hasMany(AutomationRuleRun::class); }
}
