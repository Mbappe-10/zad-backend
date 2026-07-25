<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class DigitalEmployeeTask extends Model {
    protected $fillable = ['digital_employee_id','title','instructions','status','priority','attempts','duration_ms','input','output','error_message','approval_note','approved_by','scheduled_at','started_at','completed_at','cancelled_at'];
    protected function casts(): array { return ['input'=>'array','output'=>'array','scheduled_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','cancelled_at'=>'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(DigitalEmployee::class,'digital_employee_id'); }
    public function events(): HasMany { return $this->hasMany(DigitalTaskEvent::class); }
}
