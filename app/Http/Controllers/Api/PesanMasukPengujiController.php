<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asesor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SMS Notifikasi / Pesan Masuk (portal penguji) — READ-ONLY.
 *
 * Implementasi module `pesanmasuk` sesuai docs/BACKEND_PESAN_MASUK.md:
 * log SMS yang SISTEM kirim ke no HP penguji via Gammu gateway.
 *
 * Sumber data (skema Gammu standar — jangan di-rename/migrasi):
 *   - sentitems : SMS sudah diproses (Status enum 8 nilai → badge)
 *   - outbox    : antrian kirim (semua "Menunggu")
 * Filter: DestinationNumber = asesor.no_hp.
 *
 * Perbaikan atas native:
 *   - Normalisasi format nomor (08xx + 62xx) → varians dua-duanya di-match
 *   - Default case status tak dikenal (bukan badge kosong)
 *   - LIMIT (param ?limit=, default 100, max 500)
 *   - Fallback sort InsertIntoDB utk outbox (SendingDateTime sering zero-date)
 */
class PesanMasukPengujiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // ── Guard (idem JadwalPengujiController) ──
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }
        if (! $user->isPenguji()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus penguji.',
            ], 403);
        }

        $asesor = Asesor::where('no_ktp', $user->username)
            ->orWhere('no_ktp', $user->no_ktp)
            ->first();

        if (! $asesor) {
            return response()->json([
                'success' => true,
                'data' => ['asesor' => null, 'jumlah_pesan' => 0, 'ringkasan' => $this->ringkasanKosong(), 'pesan' => []],
            ]);
        }

        // ── Normalisasi nomor HP: match 08xx dan 62xx ──
        $nohp = preg_replace('/[^0-9]/', '', (string) $asesor->no_hp);
        if ($nohp === '' || strlen($nohp) <= 8) {
            return response()->json([
                'success' => true,
                'data' => [
                    'asesor' => $this->formatAsesor($asesor),
                    'jumlah_pesan' => 0,
                    'ringkasan' => $this->ringkasanKosong(),
                    'pesan' => [],
                ],
            ]);
        }
        $variants = [$nohp];
        if (str_starts_with($nohp, '0')) {
            $variants[] = '62'.substr($nohp, 1);
        }

        $limit = min(max((int) $request->query('limit', 100), 1), 500);

        // ── QUERY 1: sentitems (SMS terproses) ──
        $sent = DB::table('sentitems')
            ->whereIn('DestinationNumber', $variants)
            ->orderByDesc('SendingDateTime')
            ->get(['SendingDateTime', 'DeliveryDateTime', 'TextDecoded', 'Status']);

        $pesan = $sent->map(fn ($r) => [
            'sumber' => 'sent',
            'waktu' => $this->fmtWaktu($r->SendingDateTime),
            'waktu_terima' => $this->fmtWaktu($r->DeliveryDateTime),
            'isi' => $r->TextDecoded,
            'status' => $r->Status,
            'status_label' => $this->statusLabel($r->Status),
            'status_warna' => $this->statusWarna($r->Status),
        ])->all();

        // ── QUERY 2: outbox (antrian — semua "Menunggu") ──
        $queue = DB::table('outbox')
            ->whereIn('DestinationNumber', $variants)
            ->orderByDesc('InsertIntoDB')
            ->orderByDesc('ID')
            ->get(['InsertIntoDB', 'SendingDateTime', 'TextDecoded']);

        foreach ($queue as $r) {
            // SendingDateTime outbox sering zero-date → pakai InsertIntoDB
            $waktu = $this->fmtWaktu($r->InsertIntoDB) ?? $this->fmtWaktu($r->SendingDateTime);
            $pesan[] = [
                'sumber' => 'queue',
                'waktu' => $waktu,
                'waktu_terima' => null,
                'isi' => $r->TextDecoded,
                'status' => 'Waiting',
                'status_label' => 'Menunggu',
                'status_warna' => 'merah',
            ];
        }

        // ── Merge + sort waktu DESC (null → akhir), lalu LIMIT ──
        usort($pesan, function ($a, $b) {
            $wa = $a['waktu'] ?? '';
            $wb = $b['waktu'] ?? '';
            if ($wa === $wb) {
                return 0;
            }
            if ($wa === '') {
                return 1;
            }
            if ($wb === '') {
                return -1;
            }

            return strcmp($wb, $wa);
        });
        $pesan = array_slice($pesan, 0, $limit);

        return response()->json([
            'success' => true,
            'data' => [
                'asesor' => $this->formatAsesor($asesor),
                'jumlah_pesan' => count($pesan),
                'ringkasan' => $this->hitungRingkasan($pesan),
                'pesan' => $pesan,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS — mapping status enum Gammu (switch spek + default case)
    // ════════════════════════════════════════════════════════════════

    private function statusLabel($status): string
    {
        return match ($status) {
            'SendingOK', 'SendingOKNoReport', 'DeliveryOK' => 'Terkirim',
            'SendingError' => 'Gagal Terkirim',
            'DeliveryFailed' => 'Gagal Terkirim',
            'Error' => 'Terjadi kesalahan sistem',
            'DeliveryPending' => 'Tertahan',
            'DeliveryUnknown' => 'Status tidak diketahui',
            default => 'Status tidak diketahui',
        };
    }

    private function statusWarna($status): string
    {
        return match ($status) {
            'SendingOK', 'SendingOKNoReport', 'DeliveryOK' => 'hijau',
            'SendingError', 'DeliveryFailed', 'Error' => 'merah',
            'DeliveryPending' => 'kuning',
            default => 'biru',
        };
    }

    private function fmtWaktu($waktu): ?string
    {
        if (empty($waktu) || str_starts_with((string) $waktu, '0000-00-00')) {
            return null;
        }

        return date('Y-m-d H:i:s', strtotime($waktu));
    }

    private function formatAsesor(Asesor $asesor): array
    {
        return [
            'id' => (int) $asesor->id,
            'nama_lengkap' => $asesor->full_name,
            'no_hp' => $asesor->no_hp,
        ];
    }

    private function ringkasanKosong(): array
    {
        return ['terkirim' => 0, 'gagal' => 0, 'menunggu' => 0, 'lainnya' => 0];
    }

    private function hitungRingkasan(array $pesan): array
    {
        $r = $this->ringkasanKosong();
        foreach ($pesan as $p) {
            match ($p['status_warna']) {
                'hijau' => $r['terkirim']++,
                'merah' => $p['status'] === 'Waiting' ? $r['menunggu']++ : $r['gagal']++,
                default => $r['lainnya']++,
            };
        }

        return $r;
    }
}
