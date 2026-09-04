<?php

namespace App\Services;

use App\Models\Asesi;
use App\Models\AsesiPersyaratanpokok;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gate kelayakan pendaftaran skema sertifikasi (portal peserta).
 *
 * Tiga syarat sesuai docs/BACKEND_PESERTA_SKEMA_SERTIFIKASI.md §Gate:
 *   1. Usia < 101 tahun (dihitung dari tgl_lahir)
 *   2. Pendidikan > 1 (jenjang pendidikan minimal)
 *   3. Semua dokumen wajib terupload (asesi_persyaratanpokok wajib='Y'
 *      → cek kolom asesi via shortcode)
 *
 * Dipakai bersama PesertaSkemaController (render gate) dan
 * PesertaPendaftaranController (enforcement server-side saat submit).
 */
class PesertaGateService
{
    /**
     * Rantai resolusi identitas peserta dari akun users
     * (idem PesertaProfilController — 4 arah fallback).
     */
    public function resolveAsesi(User $user): ?Asesi
    {
        return Asesi::where('no_ktp', $user->no_ktp)
            ->orWhere('no_pendaftaran', $user->username)
            ->orWhere('no_pendaftaran', $user->no_induk)
            ->orWhere('nohp', $user->no_telp)
            ->first();
    }

    /**
     * Usia dari tgl_lahir; null bila data lahir kosong/zero-date
     * (fail-safe, idem zero-date guard PesertaDashboardController).
     */
    public function hitungUsia(?string $tglLahir): ?int
    {
        if (empty($tglLahir) || $tglLahir === '0000-00-00') {
            return null;
        }

        return (int) \Carbon\Carbon::parse($tglLahir)->age;
    }

    /**
     * Cek file dokumen pokok peserta berdasarkan shortcode
     * (= nama kolom tabel asesi), dengan pasangan fallback legacy
     * (idem PesertaDashboardController::hasDocumentFile).
     */
    public function hasDocumentFile(Asesi $asesi, string $shortcode): bool
    {
        return match ($shortcode) {
            'sertifikat_amdal', 'sertifikat' => ! empty($asesi->sertifikat_amdal) || ! empty($asesi->sertifikat),
            'bukti_keterlibatan', 'suket' => ! empty($asesi->bukti_keterlibatan) || ! empty($asesi->suket),
            'sertifikat_kompetensi_lain', 'transkrip' => ! empty($asesi->sertifikat_kompetensi_lain) || ! empty($asesi->transkrip),
            default => ! empty($asesi->{$shortcode}),
        };
    }

    /**
     * Hitung gate 3 syarat kelayakan pendaftaran.
     *
     * @return array{
     *   usia: array{nilai: ?int, ok: bool},
     *   pendidikan: array{nilai: ?string, label: ?string, ok: bool},
     *   dokumen: array{ada: int, total: int, lengkap: bool, kurang: array},
     *   lolos: bool
     * }
     */
    /**
     * Cek apakah jenjang pendidikan memenuhi syarat minimal S1/D4 (skema jenjang >= 9).
     */
    public function cekPendidikanValid($pendidikanNilai, $minJenjang = 9): array
    {
        if (empty($pendidikanNilai)) {
            return [
                'ok' => false,
                'nilai' => null,
                'label' => 'Belum diisi',
                'pesan' => 'Pendidikan belum diisi — lengkapi profil Anda.',
            ];
        }

        $id = null;
        $label = null;

        if (is_numeric($pendidikanNilai)) {
            $id = (int) $pendidikanNilai;
            $label = DB::table('pendidikan')->where('id', $id)->value('jenjang_pendidikan');
        } else {
            $str = strtoupper(trim((string) $pendidikanNilai));
            if ($str === 'S1' || str_contains($str, 'STRATA I') || str_contains($str, 'S1')) {
                $id = 9;
                $label = 'Sarjana Strata I (S1)';
            } elseif ($str === 'D4' || str_contains($str, 'DIPLOMA IV') || str_contains($str, 'D4')) {
                $id = 10;
                $label = 'Sarjana Diploma IV (D4)';
            } elseif ($str === 'S2' || str_contains($str, 'S2')) {
                $id = 11;
                $label = 'Sarjana Strata 2 (S2)';
            } elseif ($str === 'S3' || str_contains($str, 'S3')) {
                $id = 13;
                $label = 'Sarjana Strata III (S3)';
            } elseif ($str === 'D3' || str_contains($str, 'DIPLOMA III') || str_contains($str, 'D3')) {
                $id = 8;
                $label = 'Diploma III (D3)';
            } elseif ($str === 'D2' || str_contains($str, 'D2')) {
                $id = 7;
                $label = 'Diploma II (D2)';
            } elseif ($str === 'D1' || str_contains($str, 'D1')) {
                $id = 6;
                $label = 'Diploma I (D1)';
            } elseif (str_contains($str, 'SMA') || str_contains($str, 'SMK')) {
                $id = 4;
                $label = 'SMA / SMK';
            } else {
                $row = DB::table('pendidikan')->where('jenjang_pendidikan', 'like', '%' . $pendidikanNilai . '%')->first();
                if ($row) {
                    $id = (int) $row->id;
                    $label = $row->jenjang_pendidikan;
                } else {
                    $label = (string) $pendidikanNilai;
                }
            }
        }

        // Jenjang yang setara atau di atas minimal S1 / D4 (id >= 9, kecuali SMK Plus id 20)
        // ID: 9 (S1), 10 (D4), 11 (S2), 12 (S2 Terapan), 13 (S3), 14 (Spesialis 1), 15 (Spesialis 2), 16 (Profesi), 17 (D4 Terapan), 18 (S1 Terapan), 19 (S3 Terapan)
        $jenjangS1D4Keatas = [9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19];

        $ok = false;
        if ($minJenjang >= 9) {
            // Wajib minimal S1 / D4
            $ok = in_array($id, $jenjangS1D4Keatas, true);
        } else {
            $ok = $id !== null && $id >= $minJenjang;
        }

        return [
            'ok' => $ok,
            'nilai' => (string) $pendidikanNilai,
            'label' => $label ?: (string) $pendidikanNilai,
            'pesan' => $ok ? null : 'Pendidikan minimal S1/D4 (tercatat: ' . ($label ?: $pendidikanNilai) . ')',
        ];
    }

    public function hitungGate(Asesi $asesi, $skema = null): array
    {
        // ── Syarat 1: usia < 101 tahun ──
        $usia = $this->hitungUsia((string) $asesi->tgl_lahir);
        $usiaOk = $usia !== null && $usia < 101;

        // ── Syarat 2: pendidikan minimal S1 / D4 ──
        $minJenjang = 9; // Default minimal S1/D4 untuk skema AMDAL (ATPA/KTPA)
        if ($skema && !empty($skema->jenjang)) {
            $minJenjang = (int) $skema->jenjang;
        }

        $pendidikanData = $this->cekPendidikanValid($asesi->pendidikan, $minJenjang);
        $pendidikanOk = $pendidikanData['ok'];
        $pendidikanLabel = $pendidikanData['label'];

        // ── Syarat 3: dokumen pokok wajib lengkap ──
        $wajib = AsesiPersyaratanpokok::wajib()->aktif()->orderBy('id')->get();
        $ada = 0;
        $kurang = [];
        foreach ($wajib as $p) {
            if ($this->hasDocumentFile($asesi, $p->shortcode)) {
                $ada++;
            } else {
                $kurang[] = [
                    'persyaratan' => $p->persyaratan,
                    'shortcode' => $p->shortcode,
                ];
            }
        }
        $dokLengkap = $wajib->count() > 0 && $ada === $wajib->count();

        // ── Syarat 4: profil & dokumen wajib sudah diverifikasi admin ──
        $verifDok = is_array($asesi->verifikasi_dokumen)
            ? $asesi->verifikasi_dokumen
            : (is_string($asesi->verifikasi_dokumen) ? (json_decode($asesi->verifikasi_dokumen, true) ?: []) : []);

        $wajibShortcodes = ['ijazah', 'sertifikat_amdal', 'bukti_keterlibatan', 'dokumen_amdal'];
        $allWajibVerified = true;
        foreach ($wajibShortcodes as $sc) {
            $alias = match ($sc) {
                'sertifikat_amdal' => 'sertifikat',
                'bukti_keterlibatan' => 'suket',
                default => null,
            };
            $scVerif = ($verifDok[$sc] ?? '') === 'terverifikasi' || ($alias && ($verifDok[$alias] ?? '') === 'terverifikasi');
            if (!$scVerif) {
                $allWajibVerified = false;
                break;
            }
        }

        $profilTerverifikasi = ($asesi->verifikasi === 'V') || $allWajibVerified;

        return [
            'usia' => ['nilai' => $usia, 'ok' => $usiaOk],
            'pendidikan' => [
                'nilai' => $asesi->pendidikan !== null ? (string) $asesi->pendidikan : null,
                'pesan' => $pendidikanData['pesan'] ?? null,
                'label' => $pendidikanLabel,
                'ok' => $pendidikanOk,
            ],
            'dokumen' => [
                'ada' => $ada,
                'total' => $wajib->count(),
                'lengkap' => $dokLengkap,
                'kurang' => $kurang,
            ],
            'profil' => [
                'terverifikasi' => $profilTerverifikasi,
                'status' => $asesi->verifikasi,
                'ok' => $profilTerverifikasi,
                'pesan' => $profilTerverifikasi ? null : 'Profil & dokumen belum diverifikasi oleh admin LSK.',
            ],
            'lolos' => $usiaOk && $pendidikanOk && $dokLengkap && $profilTerverifikasi,
        ];
    }
}
