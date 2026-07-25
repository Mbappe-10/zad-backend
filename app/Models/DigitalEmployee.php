<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class DigitalEmployee extends Model {
    protected $fillable = ['code','name_ar','name_en','job_title_ar','job_title_en','department','model_provider','model_name','status','risk_level','autonomy_level','monthly_budget','spent_this_month','max_daily_tasks','requires_approval','capabilities','permissions','kpis','system_prompt','owner_id','last_run_at'];
    protected function casts(): array { return ['requires_approval'=>'boolean','capabilities'=>'array','permissions'=>'array','kpis'=>'array','monthly_budget'=>'decimal:2','spent_this_month'=>'decimal:2','last_run_at'=>'datetime']; }
    public function tasks(): HasMany { return $this->hasMany(DigitalEmployeeTask::class); }
    public function rules(): HasMany { return $this->hasMany(AutomationRule::class); }
}
