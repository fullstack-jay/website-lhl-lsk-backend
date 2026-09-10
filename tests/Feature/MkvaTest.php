<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature test Penugasan MKVA / FR.VA (portal penguji)
 * sesuai docs/BACKEND_PENUGASAN_MKVA.md.
 *
 * Semua penulisan DB dibungkus transaksi test yang di-rollback —
 * tidak meninggalkan data di database.
 */
class MkvaTest extends TestCase
{
    private int $asesorId;

    private int $jadwalId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();

        // ── Seed: asesor validator + jadwal MKVA + akun penguji ──
        $this->asesorId = (int) DB::table('asesor')->insertGetId([
            'nama' => 'Validator Satu',
            'no_ktp' => 'MKVA-TEST-'.uniqid(),
            'aktif' => 'Y',
        ]);
        $noKtp = DB::table('asesor')->where('id', $this->asesorId)->value('no_ktp');

        $this->jadwalId = (int) DB::table('jadwal_asesmen')->insertGetId([
            'nama_kegiatan' => 'Uji Kompetensi MKVA Test',
            'tgl_asesmen' => now()->toDateString(),
            'jam_asesmen' => '08:00-16:00',
            'asesor_mkva1' => (string) $this->asesorId,
            'status' => 'Terkonfirmasi',
        ]);

        $this->user = User::create([
            'username' => $noKtp,
            'nama_lengkap' => 'Validator Satu',
            'no_ktp' => $noKtp,
            'password' => Hash::make('test123'),
            'level' => 'penguji',
            'blokir' => 'N',
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════

    private function token(): string
    {
        return $this->user->createToken('penguji-auth-token')->plainTextToken;
    }

    private function apiGet(string $uri, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', $token ? "Bearer {$token}" : '')->getJson($uri);
    }

    private function apiPost(string $uri, array $payload, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', $token ? "Bearer {$token}" : '')->postJson($uri, $payload);
    }

    private function apiDelete(string $uri, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', $token ? "Bearer {$token}" : '')->deleteJson($uri);
    }

    private function payloadBagian1(): array
    {
        return [
            'periode' => '1',
            'tujuan_1' => '1',
            'tujuan_7' => '1',
            'tujuan_7b' => 'Audit internal mutu',
            'konteks_1' => '1',
            'pendekatan_3' => '1',
            'askom' => '1',
            'orel_1' => 'Asesor Kompetensi A',
            'konfirmorel_1' => 'Sudah dikonfirmasi via telp',
            'leadasesor' => '1',
            'nama_leadasesor' => 'Lead Asesor B',
            'konfirmleadaseesor' => 'Konfirmasi WA',
            'stdkom' => '1',
            'skema' => '1',
            'proaktif' => '1',
            'keterampilan1' => '1',
            'nama_ketlainnya1' => 'Negotiation',
            'matriks' => [
                'ab1v' => 1,
                'ab1a' => 1,
                'pa1v' => 1,
                'pa3f' => 1,
            ],
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // TESTS
    // ════════════════════════════════════════════════════════════════

    public function test_index_daftar_jadwal_mkva(): void
    {
        $res = $this->apiGet('/api/v1/auth/penguji/mkva', $this->token());

        $res->assertStatus(200)->assertJsonPath('success', true);
        $res->assertJsonPath('data.jumlah_jadwal', 1);

        $jadwal = $res->json('data.jadwal.0');
        $this->assertEquals($this->jadwalId, $jadwal['id_jadwal']);
        $this->assertEquals('Uji Kompetensi MKVA Test', $jadwal['nama_kegiatan']);
        $this->assertFalse($jadwal['bagian1_terisi']);
        $this->assertEquals(0, $jadwal['jumlah_temuan']);
        // CQ #1: posisi validator (regression dead-code fix)
        $this->assertEquals('Validator 1', $jadwal['posisi_saya']);
        $this->assertEquals('Validator 1', $jadwal['tim_validasi'][0]['posisi']);
        $this->assertEquals('Validator Satu', $jadwal['tim_validasi'][0]['nama_lengkap']);
    }

    public function test_index_non_validator_kosong(): void
    {
        // Penguji lain (bukan tim MKVA jadwal manapun)
        $asesorLainId = (int) DB::table('asesor')->insertGetId([
            'nama' => 'Bukan Validator',
            'no_ktp' => 'MKVA-LAIN-'.uniqid(),
            'aktif' => 'Y',
        ]);
        $noKtpLain = DB::table('asesor')->where('id', $asesorLainId)->value('no_ktp');
        $userLain = User::create([
            'username' => $noKtpLain,
            'no_ktp' => $noKtpLain,
            'password' => Hash::make('test123'),
            'level' => 'penguji',
            'blokir' => 'N',
        ]);

        $res = $this->apiGet('/api/v1/auth/penguji/mkva', $userLain->createToken('t')->plainTextToken);

        $res->assertStatus(200);
        $this->assertEquals(0, $res->json('data.jumlah_jadwal'));
        $this->assertEmpty($res->json('data.jadwal'));
    }

    public function test_show_prefill_bagian1(): void
    {
        // Simpan dulu
        $this->apiPost('/api/v1/auth/penguji/mkva/'.$this->jadwalId, $this->payloadBagian1(), $this->token())
            ->assertStatus(201);

        // Pre-fill
        $res = $this->apiGet('/api/v1/auth/penguji/mkva/'.$this->jadwalId, $this->token());
        $res->assertStatus(200)->assertJsonPath('data.bagian1_terisi', true);

        $form = $res->json('data.form');
        $this->assertEquals('1', $form['periode']);
        $this->assertTrue($form['tujuan']['tujuan_1']);
        $this->assertTrue($form['tujuan']['tujuan_7']);
        $this->assertEquals('Audit internal mutu', $form['tujuan']['tujuan_7b']);
        $this->assertTrue($form['acuan_pembanding']['stdkom']);
        // Matriks terstruktur per aspek
        $this->assertEquals(1, $form['matriks_vatm_vrff']['aspek_1']['aturan_bukti']['valid']);
        $this->assertEquals(0, $form['matriks_vatm_vrff']['aspek_1']['aturan_bukti']['terkini']);
        $this->assertEquals(1, $form['matriks_vatm_vrff']['aspek_3']['prinsip']['fleksibel']);
        // Grup lain tetap scalar
        $this->assertEquals('Lead Asesor B', $form['orang_relevan']['leadasesor']['nama']);
        $this->assertEquals('Konfirmasi WA', $form['orang_relevan']['leadasesor']['hasil_konfirmasi']);
        // askom: 3 slot orel_N (regression fix mapping kolom)
        $askom = $form['orang_relevan']['askom'];
        $this->assertTrue($askom['dikonfirmasi']);
        $this->assertCount(3, $askom['asesor']);
        $this->assertEquals('Asesor Kompetensi A', $askom['asesor'][0]['nama']);
        $this->assertEquals('Sudah dikonfirmasi via telp', $askom['asesor'][0]['hasil_konfirmasi']);
        $this->assertNull($askom['asesor'][2]['nama']);
    }

    public function test_simpan_bagian1_upsert(): void
    {
        $uri = '/api/v1/auth/penguji/mkva/'.$this->jadwalId;
        $token = $this->token();

        // INSERT pertama
        $res1 = $this->apiPost($uri, $this->payloadBagian1(), $token);
        $res1->assertStatus(201)
            ->assertJsonPath('message', 'Bagian 1 MKVA Telah Tersimpan');

        // UPDATE kedua (upsert by id_jadwal)
        $payload2 = $this->payloadBagian1();
        $payload2['periode'] = '2';
        unset($payload2['tujuan_1']);
        $res2 = $this->apiPost($uri, $payload2, $token);
        $res2->assertStatus(201)
            ->assertJsonPath('message', 'Bagian 1 MKVA Telah Terupdate');

        // Tetap 1 row per jadwal; periode berubah; unchecked jadi '0' (konsisten)
        $this->assertEquals(1, DB::table('mkva')->where('id_jadwal', (string) $this->jadwalId)->count());
        $row = DB::table('mkva')->where('id_jadwal', (string) $this->jadwalId)->first();
        $this->assertEquals('2', $row->periode);
        $this->assertEquals('0', $row->tujuan_1);   // unchecked → '0' (bukan '')
        $this->assertEquals(1, $row->ab1v);
        $this->assertEquals(0, $row->ab8m);
        $this->assertEquals(1, $row->pa3f);
        $this->assertEquals('Audit internal mutu', $row->tujuan_7b);
        $this->assertEquals('Asesor Kompetensi A', $row->orel_1);
    }

    public function test_simpan_bagian1_validasi_periode_wajib(): void
    {
        $payload = $this->payloadBagian1();
        unset($payload['periode']);

        $res = $this->apiPost('/api/v1/auth/penguji/mkva/'.$this->jadwalId, $payload, $this->token());
        $res->assertStatus(422);

        $this->assertFalse(DB::table('mkva')->where('id_jadwal', (string) $this->jadwalId)->exists());
    }

    public function test_ownership_bukan_validator_403(): void
    {
        $asesorLainId = (int) DB::table('asesor')->insertGetId([
            'nama' => 'Penguji Lain',
            'no_ktp' => 'MKVA-LAIN2-'.uniqid(),
            'aktif' => 'Y',
        ]);
        $noKtpLain = DB::table('asesor')->where('id', $asesorLainId)->value('no_ktp');
        $userLain = User::create([
            'username' => $noKtpLain,
            'no_ktp' => $noKtpLain,
            'password' => Hash::make('test123'),
            'level' => 'penguji',
            'blokir' => 'N',
        ]);
        $tokenLain = $userLain->createToken('t')->plainTextToken;

        // GET form
        $this->apiGet('/api/v1/auth/penguji/mkva/'.$this->jadwalId, $tokenLain)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Anda bukan tim validasi MKVA pada jadwal ini');

        // POST simpan juga ditolak
        $this->apiPost('/api/v1/auth/penguji/mkva/'.$this->jadwalId, $this->payloadBagian1(), $tokenLain)
            ->assertStatus(403);
    }

    public function test_temuan_crud(): void
    {
        $base = '/api/v1/auth/penguji/mkva/'.$this->jadwalId;
        $token = $this->token();

        // Tambah
        $res = $this->apiPost($base.'/temuan', [
            'temuan' => 'Perangkat asesmen tidak memuat prosedur menolak bukti',
            'rekomendasi' => 'Tambah prosedur pada FR.IA.01',
        ], $token);
        $res->assertStatus(201)->assertJsonPath('message', 'Temuan berhasil ditambahkan');
        $temuanId = $res->json('data.id');

        // Duplikat → 409
        $this->apiPost($base.'/temuan', [
            'temuan' => 'Perangkat asesmen tidak memuat prosedur menolak bukti',
            'rekomendasi' => 'Tambah prosedur pada FR.IA.01',
        ], $token)->assertStatus(409);

        // Render bagian 2
        $res2 = $this->apiGet($base.'/temuan-perbaikan', $token);
        $res2->assertStatus(200);
        $temuan = collect($res2->json('data.temuan'));
        $this->assertEquals(1, $temuan->count());
        $this->assertEquals('Tambah prosedur pada FR.IA.01', $temuan->first()['rekomendasi']);

        // Hapus
        $this->apiDelete($base.'/temuan/'.$temuanId, $token)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Temuan berhasil dihapus');

        // Hapus ulang (id sudah tidak ada) → pesan tidak ditemukan
        $this->apiDelete($base.'/temuan/'.$temuanId, $token)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Temuan tidak ditemukan');
    }

    public function test_perbaikan_crud(): void
    {
        $base = '/api/v1/auth/penguji/mkva/'.$this->jadwalId;
        $token = $this->token();

        // Tambah
        $res = $this->apiPost($base.'/perbaikan', [
            'perbaikan' => 'Revisi instruksi kerja asesmen',
            'penyelesaian' => 'Update dokumen sebelum asesmen berikutnya',
            'penanggungjawab' => 'Manajer mutu',
        ], $token);
        $res->assertStatus(201)->assertJsonPath('message', 'Rencana perbaikan berhasil ditambahkan');
        $perbaikanId = $res->json('data.id');

        // Render bagian 2
        $res2 = $this->apiGet($base.'/temuan-perbaikan', $token);
        $perbaikan = collect($res2->json('data.perbaikan'));
        $this->assertEquals(1, $perbaikan->count());
        $this->assertEquals('Manajer mutu', $perbaikan->first()['penanggungjawab']);

        // Hapus (regression: dulu 500 karena Request tidak di-inject)
        $this->apiDelete($base.'/perbaikan/'.$perbaikanId, $token)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Rencana perbaikan berhasil dihapus');

        $this->assertFalse(DB::table('mkva_perbaikan')->where('id', $perbaikanId)->exists());
    }

    public function test_bagian2_indikator_bagian1(): void
    {
        $base = '/api/v1/auth/penguji/mkva/'.$this->jadwalId;
        $token = $this->token();

        // Sebelum Bagian 1 diisi
        $this->apiGet($base.'/temuan-perbaikan', $token)
            ->assertStatus(200)
            ->assertJsonPath('data.bagian1_terisi', false);

        // Setelah Bagian 1 diisi
        $this->apiPost($base, $this->payloadBagian1(), $token)->assertStatus(201);
        $this->apiGet($base.'/temuan-perbaikan', $token)
            ->assertStatus(200)
            ->assertJsonPath('data.bagian1_terisi', true);
    }

    public function test_unauthenticated_401(): void
    {
        $this->apiGet('/api/v1/auth/penguji/mkva')->assertStatus(401);
        $this->apiPost('/api/v1/auth/penguji/mkva/'.$this->jadwalId, [])->assertStatus(401);
    }
}
