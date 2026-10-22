<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderPreparationDecision extends Model {
    protected $guarded = [];
    protected function casts(): array {
        return ['response_due_at'=>'datetime','responded_at'=>'datetime','extra_minutes'=>'integer','compensation_halalas'=>'integer'];
    }
}
