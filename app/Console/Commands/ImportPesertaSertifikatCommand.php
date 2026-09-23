<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Import data peserta pemegang sertifikat dari file Excel
 * "Laporan Data Sertifikat (LSK LHL).xlsx" ke tabel `asesi`.
 *
 * Format kolom yang dikenali (berdasarkan nama header, urutan bebas):
 *   Nomor Sertifikat, Nama, NIK, Tanggal Terbit, Provinsi, Skema
 *
 * Aturan:
 * - no_pendaftaran dibangkitkan 'IMP-{NIK}' (unik per orang)
 * - Deduplikasi: baris dengan NIK sama (dalam file maupun di DB) dilewati
 * - Nomor sertifikat dinormalisasi dari homoglif Kiril → Latin
 *   (mis. "SF0037.KTРА.2025" berisi Р/А Kiril hasil ketikan Excel)
 * - Provinsi (nama UPPERCASE) dipetakan ke id data_wilayah level provinsi;
 *   yang tidak cocok disimpan NULL dan dilaporkan
 * - status_sertifikat = 'VALID', verifikasi = 'V' (pemegang sertifikat sah)
 * - Tidak membuat akun login users — khusus data pemegang sertifikat
 *
 * Usage:
 *   php artisan peserta:import-sertifikat "docs/Laporan Data Sertifikat (LSK LHL).xlsx"
 *   php artisan peserta:import-sertifikat {file} --dry-run
 */
class ImportPesertaSertifikatCommand extends Command
{
    protected $signature = 'peserta:import-sertifikat
                            {file : Path file .xlsx}
                            {--dry-run : Analisis saja, tanpa menulis ke database}';

    protected $description = 'Import pemegang sertifikat ATPA/KTPA dari Excel ke tabel asesi';

    /** Homoglif Kiril → Latin (hasil ketikan keyboard Cyrillic) */
    private array $cyrillicMap = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
        'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X',
        'а' => 'A', 'в' => 'B', 'е' => 'E', 'к' => 'K', 'м' => 'M', 'н' => 'H',
        'о' => 'O', 'р' => 'P', 'с' => 'C', 'т' => 'T', 'у' => 'Y', 'х' => 'X',
    ];

    public function handle(): int
    {
        $file = $this->argument('file');
        $dry = (bool) $this->option('dry-run');

        if (!is_file($file)) {
            $this->error("File tidak ditemukan: $file");

            return self::FAILURE;
        }
        if (!class_exists(Reader::class)) {
            $this->error('Package openspout/openspout belum ter-install');

            return self::FAILURE;
        }

        // ── Peta provinsi: nama UPPER → id_wil (level provinsi) ──
        $provMap = DB::table('data_wilayah')
            ->whereRaw('LENGTH(id_wil)=2')
            ->pluck('id_wil', 'nm_wil')
            ->mapWithKeys(fn ($id, $name) => [mb_strtoupper(trim($name)) => $id])
            ->all();

        // ── NIK yang sudah ada di DB (untuk dedup) ──
        $existingNiks = Schema::hasColumn('asesi', 'no_ktp')
            ? DB::table('asesi')->whereNotNull('no_ktp')->where('no_ktp', '!=', '')->pluck('no_ktp')->flip()
            : collect();

        $headerMap = null;
        $seenNik = [];
        $inserted = 0;
        $dupFile = 0;
        $dupDb = 0;
        $invalid = 0;
        $rowNo = 0;
        $batch = [];
        $unmatchedProv = [];
        $samples = [];
        $invalidSamples = [];

        $reader = new Reader();
        $reader->open($file);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $vals = array_map(function ($v) {
                    if ($v instanceof \DateTimeInterface) {
                        return $v->format('Y-m-d');
                    }

                    return trim((string) $v);
                }, $row->toArray());
                $rowNo++;

                // Baris judul (baris pertama biasanya "Laporan Data Sertifikat")
                if (count($vals) < 2) {
                    continue;
                }

                // ── Deteksi baris header → peta nama kolom → index ──
                if ($headerMap === null) {
                    $header = array_map(fn ($h) => mb_strtolower(preg_replace('/\s+/', ' ', $h)), $vals);
                    $idx = [];
                    foreach ($header as $i => $h) {
                        if (str_contains($h, 'nomor sertifikat')) $idx['nomor'] = $i;
                        if ($h === 'nama') $idx['nama'] = $i;
                        if ($h === 'nik') $idx['nik'] = $i;
                        if (str_contains($h, 'tanggal terbit')) $idx['tgl'] = $i;
                        if ($h === 'provinsi') $idx['prov'] = $i;
                        if ($h === 'skema') $idx['skema'] = $i;
                    }
                    if (!isset($idx['nomor'], $idx['nama'], $idx['nik'])) {
                        continue; // bukan baris header
                    }
                    $headerMap = $idx;
                    $this->info("Header terdeteksi di baris $rowNo (kolom: " . implode(',', $idx) . ')');
                    continue;
                }

                // ── Baris data ──
                $get = fn ($k) => $vals[$headerMap[$k]] ?? '';
                $nomor = $this->normalizeCert($get('nomor'));
                $nik = preg_replace('/\D/', '', $get('nik'));
                $nama = trim($get('nama'));

                if ($nomor === '' || $nik === '' || $nama === '') {
                    $invalid++;
                    if (count($invalidSamples) < 5) {
                        $invalidSamples[] = "baris $rowNo (nomor='$nomor', nik='$nik', nama='$nama')";
                    }
                    continue;
                }
                if (isset($seenNik[$nik])) {
                    $dupFile++;
                    continue;
                }
                if ($existingNiks->has($nik)) {
                    $dupDb++;
                    continue;
                }
                $seenNik[$nik] = true;

                // Tanggal terbit
                $tgl = $get('tgl');
                $tgl = $tgl !== '' && strtotime($tgl) ? date('Y-m-d', strtotime($tgl)) : null;

                // Provinsi → id wilayah
                $provRaw = mb_strtoupper($get('prov'));
                $provId = $provMap[$provRaw] ?? null;
                if ($provId === null && $provRaw !== '') {
                    // fallback pencocokan sebagian
                    foreach ($provMap as $name => $id) {
                        if (str_contains($name, $provRaw) || str_contains($provRaw, $name)) {
                            $provId = $id;
                            break;
                        }
                    }
                }
                if ($provRaw !== '' && $provId === null && !in_array($provRaw, $unmatchedProv)) {
                    $unmatchedProv[] = $provRaw;
                }

                // Skema (ATPA/KTPA) — normalisasi homoglif juga
                $skema = strtoupper($this->normalizeCert($get('skema')));
                $skema = str_replace(' ', '', $skema);

                $payload = [
                    'no_pendaftaran' => 'IMP-' . $nik,
                    'nama' => $nama,
                    'no_ktp' => $nik,
                    'propinsi' => $provId,
                    'no_sertifikat' => $nomor,
                    'jenis_sertifikat' => in_array($skema, ['ATPA', 'KTPA'], true) ? $skema : ($skema ?: null),
                    'status_sertifikat' => 'VALID',
                    'tgl_sertifikat' => $tgl,
                    'verified_by_sertifikat' => 'Import Excel',
                    'tgl_verifikasi_sertifikat' => $tgl,
                    'verifikasi' => 'V',
                    'tgl_daftar' => now()->toDateString(),
                    'angkatan' => $tgl ? (int) date('Y', strtotime($tgl)) : (int) date('Y'),
                    'blokir' => 'N',
                ];

                if (count($samples) < 5) {
                    $samples[] = $payload;
                }

                // Tabel legacy ber-collation latin1 → transliterasi karakter di
                // luar latin1 (NBSP, en-dash, kutip melengkung, dll) lalu hasilnya
                // dikembalikan ke UTF-8 agar valid bagi koneksi database
                array_walk($payload, function (&$v) {
                    if (is_string($v) && $v !== '') {
                        $t = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $v);
                        if ($t === false || $t === null) {
                            $t = preg_replace('/[^\x20-\x7E\xA0-\xFF]/u', '', $v);
                        }
                        $back = @iconv('ISO-8859-1', 'UTF-8', $t);
                        $v = ($back === false || $back === null) ? $t : $back;
                    }
                });

                if (!$dry) {
                    $batch[] = $payload;
                    if (count($batch) >= 500) {
                        DB::table('asesi')->insert($batch);
                        $inserted += count($batch);
                        $batch = [];
                    }
                } else {
                    $inserted++;
                }
            }
        }
        $reader->close();

        if (!$dry && !empty($batch)) {
            DB::table('asesi')->insert($batch);
            $inserted += count($batch);
        }

        // ── Laporan ──
        $this->line('════════════════════════════════════════');
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Impor selesai");
        $this->line("Baris data dibaca : " . ($rowNo - ($headerMap !== null ? 2 : 0)));
        $this->info("Berhasil diimpor  : $inserted");
        $this->warn("Lewati — NIK duplikat di file : $dupFile");
        $this->warn("Lewati — NIK sudah ada di DB : $dupDb");
        $this->error("Invalid (nomor/nik/nama kosong): $invalid");
        if (!empty($invalidSamples)) {
            foreach ($invalidSamples as $s) $this->line("   - $s");
        }
        if (!empty($unmatchedProv)) {
            $this->warn('Provinsi tidak cocok dgn data_wilayah (propinsi=NULL): ' . implode(', ', $unmatchedProv));
        }
        if (!empty($samples)) {
            $this->line('── 5 baris pertama ──');
            foreach ($samples as $s) {
                $this->line('  ' . $s['no_pendaftaran'] . ' | ' . $s['nama'] . ' | NIK ' . $s['no_ktp']
                    . ' | ' . $s['no_sertifikat'] . ' | ' . $s['jenis_sertifikat']
                    . ' | ' . ($s['tgl_sertifikat'] ?? '-') . ' | propinsi=' . ($s['propinsi'] ?? 'NULL'));
            }
        }
        $this->line('════════════════════════════════════════');

        return self::SUCCESS;
    }

    /** Normalisasi nomor/skema: homoglif Kiril → Latin, buang karakter non-ASCII */
    private function normalizeCert(string $value): string
    {
        $mapped = strtr($value, $this->cyrillicMap);
        // buang sisa karakter non-ASCII apa pun (emoji, zero-width, dll)
        $mapped = preg_replace('/[^\x20-\x7E]/u', '', $mapped);

        return trim($mapped);
    }
}
