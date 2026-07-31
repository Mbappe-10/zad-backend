<?php
namespace App\Services\App;
use App\Models\AppSetting;
use Illuminate\Validation\ValidationException;
class VehicleRecommendationService {
 public function recommend(string $size, float $distance): string {
  $rules=AppSetting::where('key','delivery.vehicle_rules')->value('value') ?? [];
  if(is_string($rules)) $rules=json_decode($rules,true) ?: [];
  usort($rules,fn($a,$b)=>($a['priority']??100)<=>($b['priority']??100));
  foreach($rules as $rule){ $max=$rule['max_distance_km']??null; if(in_array($size,$rule['allowed_sizes']??[],true) && ($max===null || $distance<=(float)$max)) return (string)$rule['vehicle']; }
  return 'car';
 }
 public function assertOverrideAllowed(): void { $enabled=(bool)AppSetting::where('key','delivery.owner_override_enabled')->value('value'); if(!$enabled) throw ValidationException::withMessages(['vehicle_type'=>'تجاوز قاعدة المركبة متوقف من إعدادات المالك.']); }
}
