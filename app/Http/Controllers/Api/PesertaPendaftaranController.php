<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PendaftaranSkemaMail;
use App\Models\Asesi;
use App\Models\AsesiAsesmen;
use App\Models\AsesmenUnitkompetensi;
use App\Models\BiayaSertifikasi;
use App\Models\Logdigisign;
use App\Models\Rekeningbayar;
use App\Models\SkemaKkni;
use App\Models\UnitKompetensi;
use App\Services\PesertaGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Submit pendaftaran skema sertifikasi — FR-APL-01 (portal peserta).
 *
 * Implementasi handler `daftarasesmen` dari
 * docs/BACKEND_PESERTA_SKEMA_SERTIFIKASI.md dengan perbaikan:
 *   - SATU transaksi DB utk 5 operasi inti (legacy: tanpa transaction);
 *   - email + SMS dikirim SETELAH commit, gagal tidak merusak pendaftaran;
 *   - gate kelayakan di-enforce server-side (legacy: hanya disable tombol);
 *   - biaya dihitung server-side (legacy: percaya POST);
 *   - ukom wajib min 1 unit & ttd digital wajib (keputusan desain).
 *
 * Mode PUPR sengaja TIDAK diimplementasikan (keputusan desain).
 */
class PesertaPendaftaranController extends Controller
{
    public function __construct(private PesertaGateService $gate) {}

    public function store(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }
        if (! $user->isPeserta()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Halaman ini khusus peserta.',
            ], 403);
        }

        $asesi = $this->gate->resolveAsesi($user);
        if (! $asesi) {
            return response()->json([
                'success' => false,
                'message' => 'Profil peserta belum lengkap. Lengkapi profil terlebih dahulu.',
            ], 404);
        }
        if ($asesi->blokir === 'Y') {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda diblokir. Hubungi admin LSK.',
            ], 403);
        }

        $skema = SkemaKkni::find($id);
        if (! $skema || $skema->aktif !== 'Y') {
            return response()->json(['success' => false, 'message' => 'Skema tidak ditemukan'], 404);
        }

        // ── Guard Sertifikat Aktif: Jika peserta sudah memiliki sertifikat aktif valid untuk skema ini ──
        if ($asesi->status_sertifikat === 'VALID') {
            $jenis = strtoupper(trim($asesi->jenis_sertifikat ?? ''));
            $judul = strtoupper((string) ($skema->judul ?? ''));
            $kode = strtoupper((string) ($skema->kode_skema ?? ''));

            $isMatch = false;
            if ($jenis === 'ATPA') {
                $isMatch = str_contains($judul, 'ATPA') || str_contains($judul, 'ANGGOTA TIM') || str_contains($kode, 'ATPA');
            } elseif ($jenis === 'KTPA') {
                $isMatch = str_contains($judul, 'KTPA') || str_contains($judul, 'KETUA TIM') || str_contains($kode, 'KTPA')
                    || str_contains($judul, 'ATPA') || str_contains($judul, 'ANGGOTA TIM');
            }

            if ($isMatch) {
                return response()->json([
                    'success' => false,
                    'message' => "Anda sudah memiliki sertifikat aktif {$jenis} yang terverifikasi valid" . ($asesi->no_sertifikat ? " (No: {$asesi->no_sertifikat})" : "") . ". Anda tidak dapat mendaftar uji kompetensi untuk skema ini.",
                ], 422);
            }
        }

        // ── Validasi FR-APL-01 ──
        $validator = Validator::make($request->all(), [
            'tujuan_sertifikasi' => 'required|in:Sertifikasi,Sertifikasi Ulang,PKT,RPL,Lainnya',
            'tujuan_lainnya' => 'required_if:tujuan_sertifikasi,Lainnya|nullable|string|max:255',
            'ukom' => 'required|array|min:1',
            'ukom.*' => 'integer',
            'signed' => [
                'required',
                'string',
                'starts_with:data:image/png;base64,',
            ],
            'id_jadwal' => 'nullable|integer|exists:jadwal_asesmen,id',
        ], [
            'tujuan_sertifikasi.required' => 'Tujuan sertifikasi wajib dipilih.',
            'tujuan_sertifikasi.in' => 'Tujuan sertifikasi tidak valid.',
            'tujuan_lainnya.required_if' => 'Tujuan lainnya wajib diisi bila memilih Lainnya.',
            'ukom.required' => 'Pilih minimal satu unit kompetensi.',
            'ukom.min' => 'Pilih minimal satu unit kompetensi.',
            'signed.required' => 'Tanda tangan digital wajib diisi.',
            'signed.starts_with' => 'Format tanda tangan digital tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => collect($validator->errors()->all())->first() ?: 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Unit kompetensi harus milik skema ini
        $ukomValid = UnitKompetensi::bySkema($id)->pluck('id')->all();
        $ukomDipilih = array_values(array_unique(array_map('intval', $request->input('ukom'))));
        $ukomTidakValid = array_diff($ukomDipilih, array_map('intval', $ukomValid));
        if (! empty($ukomTidakValid)) {
            return response()->json([
                'success' => false,
                'message' => 'Unit kompetensi tidak valid untuk skema ini',
            ], 422);
        }

        // ── Gate kelayakan — enforcement server-side ──
        $skema = SkemaKkni::find($id);
        $gate = $this->gate->hitungGate($asesi, $skema);
        if (! $gate['lolos']) {
            $gateError = [];
            if (! $gate['usia']['ok']) {
                $gateError[] = $gate['usia']['nilai'] === null
                    ? 'Tanggal lahir belum diisi — lengkapi profil Anda.'
                    : 'Usia Anda ('.$gate['usia']['nilai'].' tahun) tidak memenuhi syarat.';
            }
            if (! $gate['pendidikan']['ok']) {
                $gateError[] = 'Jenjang pendidikan tidak memenuhi syarat — lengkapi profil Anda.';
            }
            if (! ($gate['profil']['ok'] ?? true)) {
                $gateError[] = 'Profil & dokumen Anda belum diverifikasi oleh admin. Pendaftaran hanya dapat dilakukan setelah profil diverifikasi.';
            }
            foreach ($gate['dokumen']['kurang'] as $kurang) {
                $gateError[] = 'Dokumen wajib belum diunggah: '.($kurang['persyaratan'] ?: $kurang['shortcode']);
            }

            return response()->json([
                'success' => false,
                'message' => 'Syarat pendaftaran belum terpenuhi',
                'errors' => ['gate' => $gateError],
            ], 422);
        }

        $noPendaftaran = $asesi->no_pendaftaran;
        $tglDaftar = now()->toDateString();

        // ── TRANSAKSI: 5 operasi inti atomik ──
        try {
            [$asesmen, $biaya, $ttdFile] = DB::transaction(function () use ($request, $id, $skema, $asesi, $noPendaftaran, $tglDaftar, $ukomDipilih) {
                // 1. Dup-check (lock utk mitiga race dua submit paralel)
                $sudahAda = AsesiAsesmen::where('id_asesi', $noPendaftaran)
                    ->where('id_skemakkni', (string) $id)
                    ->where('status_asesmen', '!=', 'K')
                    ->lockForUpdate()
                    ->exists();
                if ($sudahAda) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'Maaf, Anda sudah terdaftar pada skema ini.',
                    ], 409));
                }

                // 2. Biaya server-side (bukan dari POST)
                $biaya = (int) BiayaSertifikasi::bySkema($id)->sum('nominal');

                $tujuan = $request->input('tujuan_sertifikasi');
                $idJadwal = $request->input('id_jadwal');
                $jadwal = $idJadwal ? \App\Models\JadwalAsesmen::find($idJadwal) : null;

                // 2b. INSERT pendaftaran (status/status_asesmen default 'P' oleh enum DB)
                $asesmen = AsesiAsesmen::create([
                    'id_asesi' => $noPendaftaran,
                    'id_skemakkni' => (string) $id,
                    'tujuan_sertifikasi' => $tujuan,
                    'tujuan_lainnya' => $tujuan === 'Lainnya' ? $request->input('tujuan_lainnya') : null,
                    'tgl_daftar' => $tglDaftar,
                    'biaya' => $biaya,
                    'id_jadwal' => $jadwal?->id,
                    'tgl_asesmen' => $jadwal?->tgl_asesmen,
                ]);

                // 3. Sinkron unit kompetensi: DELETE semua lama → INSERT terpilih
                AsesmenUnitkompetensi::where('id_asesi', $noPendaftaran)
                    ->where('id_skemakkni', (string) $id)
                    ->delete();
                AsesmenUnitkompetensi::insert(array_map(fn ($ukomId) => [
                    'id_asesi' => $noPendaftaran,
                    'id_skemakkni' => (string) $id,
                    'id_unitkompetensi' => (string) $ukomId,
                ], $ukomDipilih));

                // 4. Update usia asesi (kalkulasi dari tgl_lahir)
                $usia = $this->gate->hitungUsia((string) $asesi->tgl_lahir);
                if ($usia !== null) {
                    $asesi->update(['usia' => $usia]);
                }

                // 5. Tanda tangan digital: base64 → foto_tandatangan/ + logdigisign
                $ttdFile = null;
                [$meta, $base64] = explode(',', $request->input('signed'), 2);
                $binary = base64_decode($base64, true);
                if ($binary !== false && strlen($binary) > 0) {
                    $ttdFile = sprintf('ttd_%s_%d_%s.png', $noPendaftaran, $asesmen->id, uniqid());
                    \Illuminate\Support\Facades\Storage::disk('public')
                        ->put('foto_tandatangan/'.$ttdFile, $binary);

                    Logdigisign::create([
                        'id_dokumen' => 'FR-APL-01',
                        'nama_dokumen' => 'FR-APL-01 - '.$skema->judul,
                        'penandatangan' => $asesi->nama,
                        'file' => $ttdFile,
                        'url_ditandatangani' => url('storage/foto_tandatangan/'.$ttdFile),
                        'ip' => $request->ip(),
                    ]);
                }

                return [$asesmen, $biaya, $ttdFile];
            }, 3);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;   // 409 dup — biarkan dilempar apa adanya
        } catch (\Exception $e) {
            Log::error('Pendaftaran skema gagal: '.$e->getMessage(), [
                'id_asesi' => $asesi->no_pendaftaran ?? null,
                'id_skemakkni' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses pendaftaran: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }

        // ── Notifikasi SETELAH commit — gagal tidak merusak pendaftaran ──
        $rekening = Rekeningbayar::active()
            ->where('metode', '!=', 'Tunai')
            ->orderBy('id')
            ->get();

        $emailTerkirim = false;
        try {
            $emailTujuan = $asesi->email ?: $user->email;
            if ($emailTujuan) {
                Mail::to($emailTujuan)->send(new PendaftaranSkemaMail($asesi, $skema, $biaya, $rekening));
                $emailTerkirim = true;
            }
        } catch (\Exception $e) {
            Log::warning('Email konfirmasi pendaftaran gagal: '.$e->getMessage(), [
                'id_asesmen' => $asesmen->id,
            ]);
        }

        $smsMasuk = false;
        try {
            $nohp = preg_replace('/[^0-9]/', '', (string) $asesi->nohp);
            if (strlen($nohp) > 8) {
                $namaLsp = DB::table('identitas')->value('nama_lsp') ?: 'LSK';
                DB::table('outbox')->insert([
                    'DestinationNumber' => $asesi->nohp,
                    'TextDecoded' => 'Pendaftaran skema '.$skema->judul
                        .' berhasil. Biaya: Rp '.number_format($biaya, 0, ',', '.')
                        .'. Cek email untuk info pembayaran. - '.$namaLsp,
                    'CreatorID' => 'api-laravel',
                ]);
                $smsMasuk = true;
            }
        } catch (\Exception $e) {
            Log::warning('SMS antrean pendaftaran gagal: '.$e->getMessage(), [
                'id_asesmen' => $asesmen->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pendaftaran Asesmen Sukses',
            'data' => [
                'id_asesmen' => (int) $asesmen->id,
                'no_pendaftaran' => $noPendaftaran,
                'skema' => [
                    'id' => (int) $skema->id,
                    'kode_skema' => $skema->kode_skema,
                    'judul' => $skema->judul,
                ],
                'tujuan_sertifikasi' => $asesmen->tujuan_sertifikasi,
                'tgl_daftar' => $tglDaftar,
                'biaya' => $biaya,
                'biaya_formatted' => 'Rp. '.number_format($biaya, 0, ',', '.'),
                'rekening' => $rekening->map(fn ($r) => [
                    'bank' => $r->bank,
                    'norek' => $r->norek,
                    'atasnama' => $r->atasnama,
                ]),
                'tanda_tangan' => $ttdFile !== null,
                'notifikasi' => [
                    'email_terkirim' => $emailTerkirim,
                    'sms_masuk_antrean' => $smsMasuk,
                ],
            ],
        ], 201);
    }
}
