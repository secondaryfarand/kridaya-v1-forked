<?php
namespace Database\Seeders;
use App\Models\{Deal, PlanRequest, Review, User};
use Illuminate\Database\Seeder;

/** Jalankan setelah KriDayaSeeder dan AlurDemoSeeder. Mengisi ulasan dan satu permintaan Premium. */
class Update8DemoSeeder extends Seeder {
    private function u(string $id): User { return User::where('identifier', $id)->firstOrFail(); }
    private function done(int $e, int $s): Deal {
        $E = $this->u(sprintf('event%02d@dummy.id', $e)); $S = $this->u(sprintf('sponsor%02d@dummy.id', $s));
        $d = Deal::updateOrCreate(['event_user_id'=>$E->id,'sponsor_user_id'=>$S->id], [
            'initiator_id'=>$E->id,'status'=>'done','stage'=>3,'expires_at'=>now()->subDays(20),'deadline_at'=>now()->subDays(6),
            'mou_at'=>now()->subDays(18),'event_signed_at'=>now()->subDays(18),'sponsor_signed_at'=>now()->subDays(17),'ended_at'=>now()->subDays(5),
        ]);
        $c = fn ($side) => strtoupper(substr(hash_hmac('sha256', $d->id.'|'.$side.'|demo', config('app.key')), 0, 10));
        if (!$d->event_sign_code) $d->update(['event_sign_code'=>$c('event'),'sponsor_sign_code'=>$c('sponsor')]);
        return $d;
    }
    private function rv(Deal $d, User $from, User $to, int $n, string $c): void {
        Review::updateOrCreate(['deal_id'=>$d->id,'reviewer_id'=>$from->id], ['reviewee_id'=>$to->id,'rating'=>$n,'comment'=>$c]);
    }
    public function run(): void {
        $e = fn ($i) => $this->u(sprintf('event%02d@dummy.id', $i)); $s = fn ($i) => $this->u(sprintf('sponsor%02d@dummy.id', $i));

        // event06 x sponsor09 (selesai dari AlurDemoSeeder): event sudah mengulas, sponsor belum -> sponsor09 bisa mencoba form ulasan.
        $d = Deal::where('event_user_id', $e(6)->id)->where('sponsor_user_id', $s(9)->id)->first();
        if ($d) $this->rv($d, $e(6), $s(9), 5, 'Produk sampling disukai peserta dan timnya sangat responsif.');

        $d = $this->done(7, 2);
        $this->rv($d, $e(7), $s(2), 4, 'Dukungan sesuai kesepakatan, materi promosi dikirim tepat waktu.');
        $this->rv($d, $s(2), $e(7), 5, 'Panitia rapi, laporan lengkap, dokumentasi jelas.');
        $d = $this->done(8, 10);
        $this->rv($d, $e(8), $s(10), 5, 'Dokumentasi foto dan video hasilnya melebihi harapan.');
        $this->rv($d, $s(10), $e(8), 4, 'Acara berjalan baik, koordinasi cukup cepat.');

        PlanRequest::firstOrCreate(['user_id'=>$s(1)->id, 'status'=>'pending']);
        $this->command?->info('Demo ulasan dan permintaan Premium siap (sponsor01 mengajukan Premium; sponsor09 belum mengulas event06).');
    }
}
