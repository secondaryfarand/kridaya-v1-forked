<?php
namespace App\Http\Controllers;
use App\Models\{Profile, Review};
use Illuminate\Http\Request;

class DiscoverController extends Controller {
    /** Semua profil terverifikasi dari peran lawan, lengkap dengan rata-rata rating. */
    public function index(Request $r) {
        $opp = $r->user()->role === 'event' ? 'sponsor' : 'event';
        $rt = Review::selectRaw('reviewee_id, avg(rating) a, count(*) c')->groupBy('reviewee_id')->get()->keyBy('reviewee_id');
        return Profile::where('status','approved')->whereHas('user', fn ($q) => $q->where('role',$opp))->latest()->get()->map(fn ($p) => array_merge($p->toArray(), [
            'rating_avg'=>isset($rt[$p->user_id]) ? round($rt[$p->user_id]->a, 1) : null, 'rating_count'=>$rt[$p->user_id]->c ?? 0,
        ]))->values();
    }
}
