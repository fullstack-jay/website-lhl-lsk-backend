<?php

namespace Tests\Feature;

use App\Models\Asesi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature test alur Skema Sertifikasi — Portal Peserta
 * (docs/BACKEND_PESERTA_SKEMA_SERTIFIKASI.md).
 *
 * Semua penulisan DB dibungkus transaksi test yang di-rollback —
 * tidak meninggalkan data di database.
 */
class PesertaSkemaPortalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    /**
     * Peserta test lengkap: asesi + users terkait (dalam transaksi test).
     * Dokumen pokok wajib diisi + terverifikasi agar gate hijau
     * (gate 4 syarat: usia, pendidikan S1/D4, dokumen lengkap, profil terverifikasi).
     */
    private function buatPesertaLolosGate(): array
    {
        $np = 'TEST-'.uniqid();
        $asesi = Asesi::create([
            'no_pendaftaran' => $np,
            'nama' => 'Peserta Test',
            'no_ktp' => $np,
            'tgl_lahir' => '1993-02-02',
            'pendidikan' => '9',
            'ijazah' => 'ijazah_test.png',
            'sertifikat_amdal' => 'amd_test.png',
            'bukti_keterlibatan' => 'bukti_test.png',
            'dokumen_amdal' => 'dok_test.png',
            'email' => 'peserta-test@example.com',
            'nohp' => '081234567890',
            'tgl_daftar' => now()->toDateString(),
            'verifikasi' => 'V',
            'blokir' => 'N',
        ]);

        $user = User::create([
            'username' => $np,
            'nama_lengkap' => 'Peserta Test',
            'no_ktp' => $np,
            'password' => Hash::make('test123'),
            'level' => 'user',
            'blokir' => 'N',
        ]);

        return [$user, $asesi];
    }

    private function tokenUntuk($user): string
    {
        return $user->createToken('peserta-auth-token')->plainTextToken;
    }

    private function getJsonAuth(string $uri, string $token): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', "Bearer {$token}")->getJson($uri);
    }

    private function postJsonAuth(string $uri, array $payload, string $token): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', "Bearer {$token}")->postJson($uri, $payload);
    }

    private function pngBase64(): string
    {
        // 1x1 px PNG valid
        return 'data:image/png;base64,'
            .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    // ════════════════════════════════════════════════════════════════
    // TESTS
    // ════════════════════════════════════════════════════════════════

    public function test_daftar_skema_menampilkan_hanya_aktif_dengan_agregat(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $token = $this->tokenUntuk($user);

        $res = $this->getJsonAuth('/api/v1/peserta/skema', $token);

        $res->assertStatus(200)->assertJsonPath('success', true);
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $s) {
            $this->assertArrayHasKey('jumlah_persyaratan', $s);
            $this->assertArrayHasKey('jumlah_unit', $s);
            $this->assertArrayHasKey('total_biaya', $s);
            $this->assertArrayHasKey('total_biaya_formatted', $s);
            $this->assertArrayHasKey('sudah_daftar', $s);
            $this->assertFalse($s['sudah_daftar']);
        }
    }

    public function test_daftar_skema_menandai_sudah_daftar(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();

        // Skema aktif pertama
        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');

        DB::table('asesi_asesmen')->insert([
            'id_asesi' => $asesi->no_pendaftaran,
            'id_skemakkni' => $skemaId,
            'tgl_daftar' => now()->toDateString(),
            'tujuan_sertifikasi' => 'Sertifikasi',
            'biaya' => 0,
        ]);

        $token = $this->tokenUntuk($user);
        $res = $this->getJsonAuth('/api/v1/peserta/skema', $token);

        $res->assertStatus(200);
        $target = collect($res->json('data'))->firstWhere('id', (int) $skemaId);
        $this->assertNotNull($target);
        $this->assertTrue($target['sudah_daftar']);
    }

    public function test_detail_skema_menampilkan_gate_dan_persyaratan(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');

        $token = $this->tokenUntuk($user);
        $res = $this->getJsonAuth("/api/v1/peserta/skema/{$skemaId}", $token);

        $res->assertStatus(200)->assertJsonPath('success', true);
        $res->assertJsonStructure([
            'data' => [
                'skema', 'persyaratan', 'biaya', 'unit_kompetensi',
                'gate', 'dokumen_saya', 'tahun_doc_range', 'upload_max_mb',
            ],
        ]);
        $gate = $res->json('data.gate');
        $this->assertTrue($gate['usia']['ok']);
        $this->assertTrue($gate['pendidikan']['ok']);
        $this->assertTrue($gate['dokumen']['lengkap']);
        $this->assertTrue($gate['lolos']);
    }

    public function test_detail_skema_tak_aktif_404(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $nonaktifId = DB::table('skema_kkni')->where('aktif', '!=', 'Y')->value('id')
            ?? DB::table('skema_kkni')->max('id') + 999;

        $token = $this->tokenUntuk($user);
        $this->getJsonAuth("/api/v1/peserta/skema/{$nonaktifId}", $token)
            ->assertStatus(404);
    }

    public function test_upload_dokumen_sukses_lalu_duplikat_tolak_409(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');
        $kategoriId = DB::table('skema_persyaratan')->where('id_skemakkni', $skemaId)->value('id');
        $this->assertNotNull($kategoriId, 'Skema test butuh skema_persyaratan');

        $token = $this->tokenUntuk($user);
        $payload = [
            'skema_persyaratan' => (string) $kategoriId,
            'nama_doc' => 'Pengalaman Kerja',
            'tahun_doc' => 2024,
            'nomor_doc' => 'SK-001',
            'tgl_doc' => '2024-06-01',
        ];

        // 1. Insert pertama → sukses
        $res = $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/dokumen", $payload, $token);
        $res->assertStatus(201)->assertJsonPath('success', true);
        $this->assertEquals('Tambah Data Dokumen Sukses', $res->json('message'));

        // 2. Duplikat identik (status masih P) → 409 (fix bug legacy)
        $res2 = $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/dokumen", $payload, $token);
        $res2->assertStatus(409);
    }

    public function test_upload_dokumen_revisi_setelah_ditolak(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');
        $kategoriId = DB::table('skema_persyaratan')->where('id_skemakkni', $skemaId)->value('id');

        // Dokumen yang sudah ditolak admin
        DB::table('asesi_doc')->insert([
            'id_asesi' => $asesi->no_pendaftaran,
            'id_skemakkni' => $skemaId,
            'skema_persyaratan' => $kategoriId,
            'nama_doc' => 'Pengalaman Kerja',
            'tahun_doc' => 2024,
            'nomor_doc' => 'SK-002',
            'tgl_doc' => '2024-06-01',
            'status' => 'R',
        ]);

        $token = $this->tokenUntuk($user);
        $res = $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/dokumen", [
            'skema_persyaratan' => (string) $kategoriId,
            'nama_doc' => 'Pengalaman Kerja',
            'tahun_doc' => 2024,
            'nomor_doc' => 'SK-002',
            'tgl_doc' => '2024-06-01',
        ], $token);

        $res->assertStatus(201);
        $this->assertEquals('Perbaikan Data Dokumen Sukses', $res->json('message'));
    }

    public function test_upload_dokumen_kategori_bukan_milik_skema_ditolak(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');
        $kategoriLain = DB::table('skema_persyaratan')
            ->where('id_skemakkni', '!=', $skemaId)->value('id');
        $this->assertNotNull($kategoriLain);

        $token = $this->tokenUntuk($user);
        $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/dokumen", [
            'skema_persyaratan' => (string) $kategoriLain,
            'nama_doc' => 'X',
            'tahun_doc' => 2024,
            'tgl_doc' => '2024-06-01',
        ], $token)->assertStatus(422);
    }

    public function test_library_dan_addfromlib(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');
        $kategoriId = DB::table('skema_persyaratan')->where('id_skemakkni', $skemaId)->value('id');

        // Dokumen milik peserta di skema lain (fiktif: skema id 999999)
        DB::table('asesi_doc')->insert([
            'id_asesi' => $asesi->no_pendaftaran,
            'id_skemakkni' => '999999',
            'skema_persyaratan' => '1',
            'nama_doc' => 'Sertifikat Lama',
            'tahun_doc' => 2023,
            'nomor_doc' => 'LIB-001',
            'tgl_doc' => '2023-01-01',
            'file' => 'lib_test_file.pdf',
            'status' => 'P',
        ]);

        $token = $this->tokenUntuk($user);

        // 1. Library menampilkan file
        $lib = $this->getJsonAuth('/api/v1/peserta/dokumen-library', $token);
        $lib->assertStatus(200);
        $this->assertTrue(collect($lib->json('data'))->contains('file', 'lib_test_file.pdf'));

        // 2. Add-from-lib ke skema aktif → sukses
        $res = $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/dokumen-library", [
            'skema_persyaratan' => (string) $kategoriId,
            'nama_doc' => 'Sertifikat Lama',
            'tahun_doc' => 2023,
            'nomor_doc' => 'LIB-001',
            'tgl_doc' => '2023-01-01',
            'file' => 'lib_test_file.pdf',
        ], $token);
        $res->assertStatus(201);
        $this->assertEquals('lib_test_file.pdf', $res->json('data.file'));

        // 3. File milik orang lain → 422
        $res2 = $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/dokumen-library", [
            'skema_persyaratan' => (string) $kategoriId,
            'nama_doc' => 'Curi File',
            'tahun_doc' => 2023,
            'nomor_doc' => 'X-1',
            'tgl_doc' => '2023-01-01',
            'file' => 'file_orang_lain.pdf',
        ], $token);
        $res2->assertStatus(422);
    }

    public function test_hapus_dokumen_status_p_saja(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');
        $kategoriId = DB::table('skema_persyaratan')->where('id_skemakkni', $skemaId)->value('id');

        $idP = DB::table('asesi_doc')->insertGetId([
            'id_asesi' => $asesi->no_pendaftaran,
            'id_skemakkni' => $skemaId,
            'skema_persyaratan' => $kategoriId,
            'nama_doc' => 'Doc Pending',
            'tahun_doc' => 2024,
            'tgl_doc' => '2024-01-01',
            'status' => 'P',
        ]);
        $idA = DB::table('asesi_doc')->insertGetId([
            'id_asesi' => $asesi->no_pendaftaran,
            'id_skemakkni' => $skemaId,
            'skema_persyaratan' => $kategoriId,
            'nama_doc' => 'Doc Approved',
            'tahun_doc' => 2024,
            'tgl_doc' => '2024-01-01',
            'status' => 'A',
        ]);

        $token = $this->tokenUntuk($user);

        // Status A → 409
        $this->deleteJson("/api/v1/peserta/dokumen/{$idA}", [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(409);

        // Status P → sukses
        $this->deleteJson("/api/v1/peserta/dokumen/{$idP}", [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(200);

        $this->assertFalse(DB::table('asesi_doc')->where('id', $idP)->exists());
    }

    public function test_submit_pendaftaran_sukses_end_to_end(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');
        $unitIds = DB::table('unit_kompetensi')->where('id_skemakkni', $skemaId)->pluck('id')->all();
        $this->assertNotEmpty($unitIds, 'Skema test butuh unit kompetensi');

        $token = $this->tokenUntuk($user);
        $res = $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/pendaftaran", [
            'tujuan_sertifikasi' => 'Sertifikasi',
            'ukom' => [(int) $unitIds[0]],
            'signed' => $this->pngBase64(),
        ], $token);

        $res->assertStatus(201)->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pendaftaran Asesmen Sukses');

        // INSERT asesi_asesmen
        $row = DB::table('asesi_asesmen')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->where('id_skemakkni', $skemaId)
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('Sertifikasi', $row->tujuan_sertifikasi);
        $this->assertEquals(now()->toDateString(), $row->tgl_daftar);
        $this->assertEquals((int) DB::table('biaya_sertifikasi')->where('id_skemakkni', $skemaId)->sum('nominal'), (int) $row->biaya);

        // Unit kompetensi tersinkron
        $ukomCount = DB::table('asesmen_unitkompetensi')
            ->where('id_asesi', $asesi->no_pendaftaran)
            ->where('id_skemakkni', $skemaId)
            ->count();
        $this->assertEquals(1, $ukomCount);

        // Usia ter-update
        $this->assertEquals(33, (int) DB::table('asesi')->where('no_pendaftaran', $asesi->no_pendaftaran)->value('usia'));

        // Log ttd tercatat
        $log = DB::table('logdigisign')->where('penandatangan', 'Peserta Test')->orderBy('id', 'desc')->first();
        $this->assertNotNull($log);
        $this->assertEquals('FR-APL-01', $log->id_dokumen);
    }

    public function test_submit_duplikat_tolak_409(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');
        $unitIds = DB::table('unit_kompetensi')->where('id_skemakkni', $skemaId)->pluck('id')->all();

        DB::table('asesi_asesmen')->insert([
            'id_asesi' => $asesi->no_pendaftaran,
            'id_skemakkni' => $skemaId,
            'tgl_daftar' => now()->toDateString(),
            'tujuan_sertifikasi' => 'Sertifikasi',
            'biaya' => 0,
        ]);

        $token = $this->tokenUntuk($user);
        $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/pendaftaran", [
            'tujuan_sertifikasi' => 'Sertifikasi',
            'ukom' => [(int) $unitIds[0]],
            'signed' => $this->pngBase64(),
        ], $token)->assertStatus(409);
    }

    public function test_submit_gate_merah_ditolak_422(): void
    {
        // Peserta TANPA dokumen wajib & pendidikan rendah
        $np = 'TEST-'.uniqid();
        Asesi::create([
            'no_pendaftaran' => $np,
            'nama' => 'Peserta Gate Merah',
            'no_ktp' => $np,
            'tgl_lahir' => '1993-02-02',
            'pendidikan' => '1',   // tidak lolos (harus > 1)
            'email' => 'gate-merah@example.com',
            'tgl_daftar' => now()->toDateString(),
            'verifikasi' => 'P',
            'blokir' => 'N',
        ]);
        $user = User::create([
            'username' => $np,
            'nama_lengkap' => 'Peserta Gate Merah',
            'no_ktp' => $np,
            'password' => Hash::make('test123'),
            'level' => 'user',
            'blokir' => 'N',
        ]);

        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');
        $unitIds = DB::table('unit_kompetensi')->where('id_skemakkni', $skemaId)->pluck('id')->all();

        $token = $this->tokenUntuk($user);
        $res = $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/pendaftaran", [
            'tujuan_sertifikasi' => 'Sertifikasi',
            'ukom' => [(int) $unitIds[0]],
            'signed' => $this->pngBase64(),
        ], $token);

        $res->assertStatus(422);
        $this->assertArrayHasKey('gate', $res->json('errors') ?? []);

        // Pendaftaran TIDAK ikut ter-insert (enforcement sebelum transaksi)
        $this->assertFalse(DB::table('asesi_asesmen')
            ->where('id_asesi', $np)->where('id_skemakkni', $skemaId)->exists());
    }

    public function test_submit_tanpa_ukom_dan_tanpa_ttd_ditolak_422(): void
    {
        [$user, $asesi] = $this->buatPesertaLolosGate();
        $skemaId = (string) DB::table('skema_kkni')->where('aktif', 'Y')->orderBy('id')->value('id');

        $token = $this->tokenUntuk($user);

        // Tanpa ukom
        $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/pendaftaran", [
            'tujuan_sertifikasi' => 'Sertifikasi',
            'signed' => $this->pngBase64(),
        ], $token)->assertStatus(422);

        // Tanpa ttd
        $this->postJsonAuth("/api/v1/peserta/skema/{$skemaId}/pendaftaran", [
            'tujuan_sertifikasi' => 'Sertifikasi',
            'ukom' => [1],
        ], $token)->assertStatus(422);
    }

    public function test_akses_tanpa_token_401(): void
    {
        $this->getJson('/api/v1/peserta/skema')->assertStatus(401);
    }
}
