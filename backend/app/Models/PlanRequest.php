<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlanRequest extends Model {
    protected $fillable = ['user_id','status','note','decided_at'];
    protected $casts = ['decided_at'=>'datetime'];
    public function user() { return $this->belongsTo(User::class); }
}
