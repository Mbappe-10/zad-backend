<?php
namespace App\Http\Controllers\Api\App;
use App\Http\Controllers\Controller;
use App\Models\AppGuestSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class GuestSessionController extends Controller {
 public function store(Request $r): JsonResponse { $d=$r->validate(['device_id'=>'nullable|string|max:191','push_token'=>'nullable|string|max:500','latitude'=>'nullable|numeric|between:-90,90','longitude'=>'nullable|numeric|between:-180,180','permissions'=>'nullable|array']); $s=AppGuestSession::create([...$d,'last_seen_at'=>now()]); return response()->json(['guest_session_id'=>$s->id,'mode'=>'guest','next'=>'home'],201); }
 public function update(Request $r, AppGuestSession $guest): JsonResponse { $d=$r->validate(['push_token'=>'nullable|string|max:500','latitude'=>'nullable|numeric|between:-90,90','longitude'=>'nullable|numeric|between:-180,180','permissions'=>'nullable|array']); $guest->update([...$d,'last_seen_at'=>now()]); return response()->json(['data'=>$guest->fresh()]); }
}
