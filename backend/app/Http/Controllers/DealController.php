<?php
namespace App\Http\Controllers;
use App\Models\{Deal, Document, Fulfillment, Message, Review, User};
use App\Support\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DealController extends Controller {
    private function mine(Request $r, Deal $d): Deal {
        abort_unless(in_array($r->user()->id, [$d->event_user_id, $d->sponsor_user_id]), 403);
        return $d;
    }
    private function side(Request $r, Deal $d): string { return $r->user()->id === $d->event_user_id ? 'event' : 'sponsor'; }
    private function active(Request $r, Deal $d): Deal { abort_unless($this->mine($r,$d)->status === 'active', 422, 'Kerja sama belum aktif'); return $d; }

    public function index(Request $r) {
        $u = $r->user();
        return $u->deals()->with(['event','sponsor','messages','fulfillments','reviews'])->latest()->get()->map(function ($d) use ($u) {
            $a = $d->toArray();
            $a['partner_docs'] = $u->role === 'sponsor' && in_array($d->status, ['pending','active','done'])
                ? Document::where('user_id', $d->event_user_id)->whereIn('type', ['proposal','rab','panitia','dok'])->get(['id','type','path'])
                    ->map(fn ($x) => ['id'=>$x->id,'type'=>$x->type,'name'=>basename($x->path)])->values() : [];
            // Ulasan pihak lain baru terlihat setelah kamu mengirim ulasanmu sendiri.
            $mine = $d->reviews->firstWhere('reviewer_id', $u->id);
            $a['review_mine'] = $mine;
            $a['review_theirs'] = $mine ? $d->reviews->first(fn ($x) => $x->reviewer_id !== $u->id) : null;
            unset($a['reviews']);
            return $a;
        });
    }
    /** Kirim minat beserta pesan pembuka. Free: 5 pitch per bulan (dihitung di server). */
    public function store(Request $r) {
        $u = $r->user(); $u->refreshQuota();
        $v = $r->validate(['targets'=>'required|array|max:20','targets.*'=>'integer|exists:users,id','pitch'=>'nullable|string|max:500']);
        abort_if($u->plan === 'free' && $u->matches_used + count($v['targets']) > 5, 429, 'Kuota pitch Free (5 per bulan) tidak cukup. Upgrade ke Premium.');
        $new = 0;
        foreach ($v['targets'] as $tid) {
            $o = User::with('profile')->findOrFail($tid); abort_if($o->role === $u->role || $o->role === 'admin', 422, 'Target tidak valid');
            [$e,$s] = $u->role === 'event' ? [$u->id,$o->id] : [$o->id,$u->id];
            $d = Deal::firstOrCreate(['event_user_id'=>$e,'sponsor_user_id'=>$s,'status'=>'pending'], ['initiator_id'=>$u->id,'expires_at'=>now()->addDay(),'pitch'=>$v['pitch'] ?? null]);
            if ($d->wasRecentlyCreated) {
                $new++;
                Notify::to($o, 'Ada minat kerja sama baru', ($u->profile?->name ?? $u->name).' tertarik bekerja sama denganmu. Buka Workspace untuk menjawab dalam 1x24 jam.');
            }
        }
        $u->increment('matches_used', $new);
        return response()->json(['ok'=>true,'created'=>$new], 201);
    }
    public function respond(Request $r, Deal $deal) {
        $this->mine($r,$deal);
        abort_unless($deal->status === 'pending' && $deal->initiator_id !== $r->user()->id, 422);
        abort_if($deal->expires_at->isPast(), 410, 'Batas persetujuan sudah lewat');
        $ok = $r->validate(['accept'=>'required|boolean'])['accept'];
        $deal->update($ok ? ['status'=>'active','deadline_at'=>now()->addDays(14)] : ['status'=>'rejected']);
        if ($ok && $deal->pitch) $deal->messages()->create(['user_id'=>$deal->initiator_id,'body'=>$deal->pitch]); // pesan pembuka jadi chat pertama
        Notify::to(User::find($deal->initiator_id), $ok ? 'Minatmu diterima' : 'Minatmu belum diterima',
            $ok ? 'Kerja sama sudah aktif. Buka Workspace untuk mulai berdiskusi dan membuat MoU.' : 'Pihak lain belum dapat menerima minatmu kali ini.');
        return $deal;
    }
    public function sendMessage(Request $r, Deal $deal) {
        $this->active($r,$deal);
        $v = $r->validate(['body'=>'nullable|string|max:2000','file'=>'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png,docx,zip']);
        $f = $r->file('file');
        abort_if(trim($v['body'] ?? '') === '' && !$f, 422, 'Tulis pesan atau lampirkan berkas.');
        return $deal->messages()->create(['user_id'=>$r->user()->id,'body'=>$v['body'] ?? '','file_path'=>$f?->store("deals/{$deal->id}/chat"),'file_name'=>$f?->getClientOriginalName()]);
    }
    public function downloadMessage(Request $r, Deal $deal, Message $message) {
        $this->mine($r,$deal);
        abort_unless($message->deal_id === $deal->id && $message->file_path && Storage::exists($message->file_path), 404, 'File tidak ditemukan di server');
        return Storage::download($message->file_path, $message->file_name);
    }
    /** Ulasan setelah kerja sama selesai. Satu ulasan per pihak. */
    public function review(Request $r, Deal $deal) {
        $this->mine($r,$deal);
        abort_unless($deal->status === 'done', 422, 'Ulasan hanya bisa diberikan setelah kerja sama selesai.');
        $v = $r->validate(['rating'=>'required|integer|between:1,5','comment'=>'nullable|string|max:500']);
        abort_if(Review::where('deal_id',$deal->id)->where('reviewer_id',$r->user()->id)->exists(), 422, 'Kamu sudah memberi ulasan.');
        $to = $r->user()->id === $deal->event_user_id ? $deal->sponsor_user_id : $deal->event_user_id;
        $rv = Review::create(['deal_id'=>$deal->id,'reviewer_id'=>$r->user()->id,'reviewee_id'=>$to,'rating'=>$v['rating'],'comment'=>$v['comment'] ?? null]);
        Notify::to(User::find($to), 'Kamu menerima ulasan', 'Pihak lain memberi ulasan untuk kerja samamu. Beri ulasanmu juga untuk melihatnya.');
        return $rv;
    }
    public function mou(Request $r, Deal $deal) { $this->active($r,$deal)->update(['mou_at'=>now()]); return $deal; }
    /** Tanda tangan = kode verifikasi unik (ditampilkan sebagai barcode) yang bisa dicek publik lewat /verify/{kode}. */
    public function sign(Request $r, Deal $deal) {
        abort_unless($this->active($r,$deal)->mou_at, 422, 'MoU belum dibuat');
        $side = $this->side($r,$deal);
        abort_if($deal->{$side.'_signed_at'}, 422, 'Kamu sudah menandatangani MoU ini.');
        $code = strtoupper(substr(hash_hmac('sha256', "{$deal->id}|$side|{$r->user()->id}|".now()->timestamp, config('app.key')), 0, 10));
        $deal->update([$side.'_signed_at'=>now(), $side.'_sign_code'=>$code]);
        return $deal;
    }
    public function fulfil(Request $r, Deal $deal) {
        $this->active($r,$deal);
        abort_unless($deal->event_signed_at && $deal->sponsor_signed_at, 422, 'MoU belum ditandatangani kedua pihak');
        $v = $r->validate(['title'=>'required|string|max:150','note'=>'nullable|string|max:1000','value'=>'nullable|integer|min:0','file'=>'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,docx,svg,zip']);
        $f = $r->file('file');
        return $deal->fulfillments()->create([
            'user_id'=>$r->user()->id,'kind'=>$this->side($r,$deal)==='sponsor'?'material':'proof','title'=>$v['title'],
            'note'=>$v['note'] ?? null,'value'=>$v['value'] ?? 0,'path'=>$f?->store("deals/{$deal->id}"),'file_name'=>$f?->getClientOriginalName(),
        ]);
    }
    public function verify(Request $r, Deal $deal, Fulfillment $fulfillment) {
        $this->active($r,$deal);
        abort_unless($fulfillment->deal_id === $deal->id && $fulfillment->user_id !== $r->user()->id, 403, 'Hanya pihak lawan yang bisa memverifikasi');
        $fulfillment->update(['verified_at'=>now()]); return $fulfillment;
    }
    public function download(Request $r, Deal $deal, Fulfillment $fulfillment) {
        $this->mine($r,$deal);
        abort_unless($fulfillment->deal_id === $deal->id && $fulfillment->path && Storage::exists($fulfillment->path), 404, 'File tidak ditemukan di server');
        return Storage::download($fulfillment->path, $fulfillment->file_name ?: basename($fulfillment->path));
    }
    public function emailReport(Request $r, Deal $deal) {
        $this->mine($r,$deal); $deal->load('fulfillments');
        $rows = $deal->fulfillments->map(fn ($f) => '- '.($f->kind==='material'?'Sponsor':'Event').': '.$f->title.' (Rp '.number_format($f->value,0,',','.').') '.($f->verified_at?'[terverifikasi]':'[menunggu]'))->implode("\n");
        Notify::to($r->user(), 'Ringkasan kerja sama', "Ringkasan kerja sama #{$deal->id}\n\n".($rows ?: 'Belum ada deliverables.'));
        return ['ok'=>true];
    }
    public function end(Request $r, Deal $deal) {
        $this->active($r,$deal);
        abort_unless($deal->fulfillments()->exists() && !$deal->fulfillments()->whereNull('verified_at')->exists(), 422, 'Semua deliverables harus terverifikasi');
        $deal->update(['status'=>'done','ended_at'=>now()]); return $deal;
    }
}
