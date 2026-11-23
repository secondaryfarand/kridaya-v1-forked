<?php
namespace App\Console\Commands;
use App\Models\Deal;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RemindDeadlines extends Command {
    protected $signature = 'kridaya:remind-deadlines';
    protected $description = 'Ingatkan kedua pihak saat tenggat pemenuhan tinggal 3 hari atau kurang';
    public function handle(): int {
        $n = 0;
        Deal::with(['event.profile','sponsor.profile','fulfillments'])->where('status','active')->whereNotNull('deadline_at')
            ->whereBetween('deadline_at', [now(), now()->addDays(3)])->get()->each(function (Deal $d) use (&$n) {
                if ($d->fulfillments->count() > 0 && $d->fulfillments->whereNull('verified_at')->count() === 0) return; // semua sudah terverifikasi
                $days = max(0, (int) ceil(now()->diffInHours($d->deadline_at, false) / 24));
                foreach ([$d->event, $d->sponsor] as $u) {
                    if (Cache::add("remind:{$d->id}:{$u->id}:".today()->toDateString(), 1, 86400)) {
                        Notify::to($u, 'Pengingat tenggat kerja sama', "Tenggat pemenuhan kerja sama #{$d->id} tinggal $days hari. Selesaikan dan verifikasi deliverables di Workspace."); $n++;
                    }
                }
            });
        $this->info("$n pengingat dikirim"); return self::SUCCESS;
    }
}
