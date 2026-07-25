<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApprovalRequest extends Model {
 protected $fillable=['type','approvable_type','approvable_id','requested_by','assigned_to','status','payload','reason','decided_by','decided_at'];
 protected function casts(): array { return ['payload'=>'array','decided_at'=>'datetime']; }
}
