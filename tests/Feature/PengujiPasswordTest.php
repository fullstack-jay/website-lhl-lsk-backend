<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature test Ubah Password + Lupa Password Penguji
 * sesuai docs/BACKEND_LUPA_PASSWORD_PENGUJI.md.
 *
 * Semua penulisan DB dibungkus transaksi test yang di-rollback.
 */
class PengujiPasswordTest extends TestCase
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
     * Seed asesor + user penguji. $hashMode: 'bcrypt' | 'md5'.
     * md5 disimpan via DB::table — model User punya cast 'hashed' yang
     * otomatis bcrypt-kan assign via Eloquent (kondisi legacy nyata = raw DB).
     */
    private function seedPenguji(string $hashMode = 'bcrypt', string $password = 'PasswordLama99'): array
    {
        $noKtp = 'PWD-TEST-'.uniqid();
        $hash = $hashMode === 'md5' ? md5($password) : Hash::make($password);

        $asesorId = (int) DB::table('asesor')->insertGetId([
            'nama' => 'Penguji Password Test',
            'no_ktp' => $noKtp,
            'no_induk' => 'REG.'.uniqid(),
            'no_hp' => '081234567890',
            'tgl_lahir' => '1990-05-17',
            'password' => $hash,
            'aktif' => 'Y',
        ]);

        DB::table('users')->insert([
            'username' => $noKtp,
            'nama_lengkap' => 'Penguji Password Test',
            'no_ktp' => $noKtp,
            'password' => $hash,
            'level' => 'penguji',
            'blokir' => 'N',
        ]);
        $user = User::where('username', $noKtp)->first();

        return [$user, $asesorId];
    }

    private function apiPost(string $uri, array $payload, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', $token ? "Bearer {$token}" : '')->postJson($uri, $payload);
    }

    // ════════════════════════════════════════════════════════════════
    // FITUR A — UBAH PASSWORD SENDIRI
    // ════════════════════════════════════════════════════════════════

    public function test_ubah_password_sukses_bcrypt(): void
    {
        [$user] = $this->seedPenguji('bcrypt', 'PasswordLama99');
        $token = $user->createToken('penguji-auth-token')->plainTextToken;

        $res = $this->apiPost('/api/v1/auth/penguji/ubah-password', [
            'password_lama' => 'PasswordLama99',
            'password_baru' => 'PasswordBaru123',
            'password_ulangi' => 'PasswordBaru123',
        ], $token);

        $res->assertStatus(200)
            ->assertJsonPath('message', 'Ganti Password Berhasil, silahkan login kembali');

        // users ter-update bcrypt
        $userHash = DB::table('users')->where('username', $user->username)->value('password');
        $this->assertTrue(Hash::check('PasswordBaru123', $userHash));

        // asesor mirror ikut ter-update (dual-write)
        $asesorHash = DB::table('asesor')->where('no_ktp', $user->username)->value('password');
        $this->assertTrue(Hash::check('PasswordBaru123', $asesorHash));

        // logout paksa: token revoked
        $this->assertTrue($user->tokens()->doesntExist());
    }

    public function test_ubah_password_sukses_dari_md5_legacy(): void
    {
        [$user] = $this->seedPenguji('md5', 'passlama');
        $token = $user->createToken('t')->plainTextToken;

        $res = $this->apiPost('/api/v1/auth/penguji/ubah-password', [
            'password_lama' => 'passlama',
            'password_baru' => 'PasswordBaru123',
            'password_ulangi' => 'PasswordBaru123',
        ], $token);

        $res->assertStatus(200);

        $userHash = DB::table('users')->where('username', $user->username)->value('password');
        $this->assertTrue(Hash::check('PasswordBaru123', $userHash));
    }

    public function test_ubah_password_lama_salah_422(): void
    {
        [$user] = $this->seedPenguji('bcrypt', 'PasswordLama99');
        $token = $user->createToken('t')->plainTextToken;

        $this->apiPost('/api/v1/auth/penguji/ubah-password', [
            'password_lama' => 'SalahTotal123',
            'password_baru' => 'PasswordBaru123',
            'password_ulangi' => 'PasswordBaru123',
        ], $token)->assertStatus(422)
            ->assertJsonPath('message', 'Anda salah memasukkan Password Lama');
    }

    public function test_ubah_password_min_8_karakter_422(): void
    {
        [$user] = $this->seedPenguji();
        $token = $user->createToken('t')->plainTextToken;

        $this->apiPost('/api/v1/auth/penguji/ubah-password', [
            'password_lama' => 'PasswordLama99',
            'password_baru' => 'pendek',
            'password_ulangi' => 'pendek',
        ], $token)->assertStatus(422)
            ->assertJsonPath('message', 'Password minimal 8 karakter');
    }

    public function test_ubah_password_konfirmasi_tak_cocok_422(): void
    {
        [$user] = $this->seedPenguji();
        $token = $user->createToken('t')->plainTextToken;

        $this->apiPost('/api/v1/auth/penguji/ubah-password', [
            'password_lama' => 'PasswordLama99',
            'password_baru' => 'PasswordBaru123',
            'password_ulangi' => 'BedaLagi123',
        ], $token)->assertStatus(422)
            ->assertJsonPath('message', 'Password baru belum cocok');
    }

    public function test_ubah_password_non_penguji_403(): void
    {
        $admin = User::create([
            'username' => 'admin-pwd-'.uniqid(),
            'password' => Hash::make('test123'),
            'level' => 'admin',
            'blokir' => 'N',
        ]);

        $this->apiPost('/api/v1/auth/penguji/ubah-password', [
            'password_lama' => 'test123',
            'password_baru' => 'PasswordBaru123',
            'password_ulangi' => 'PasswordBaru123',
        ], $admin->createToken('t')->plainTextToken)->assertStatus(403);
    }

    // ════════════════════════════════════════════════════════════════
    // FITUR B — LUPA PASSWORD
    // ════════════════════════════════════════════════════════════════

    public function test_lupa_password_sukses_nomorktp(): void
    {
        [$user, $asesorId] = $this->seedPenguji('md5', 'passlama');
        $noKtp = DB::table('asesor')->where('id', $asesorId)->value('no_ktp');
        $noInduk = DB::table('asesor')->where('id', $asesorId)->value('no_induk');

        $res = $this->apiPost('/api/v1/auth/penguji/lupa-password', [
            'npm' => $noInduk,
            'pertanyaan' => 'nomorktp',
            'jawaban' => $noKtp,
        ]);

        $res->assertStatus(200)->assertJsonPath('success', true);

        $passwordBaru = $res->json('data.password_baru');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $passwordBaru);

        // Dual-write: users + asesor (login baca users!)
        $userHash = DB::table('users')->where('username', $noKtp)->value('password');
        $this->assertTrue(Hash::check($passwordBaru, $userHash));
        $asesorHash = DB::table('asesor')->where('id', $asesorId)->value('password');
        $this->assertTrue(Hash::check($passwordBaru, $asesorHash));

        // SMS masuk antrean outbox
        $this->assertEquals(true, $res->json('data.sms_masuk_antrean'));
        $outbox = DB::table('outbox')->where('DestinationNumber', '081234567890')
            ->where('CreatorID', 'api-laravel')->orderByDesc('ID')->first();
        $this->assertNotNull($outbox);
        $this->assertStringContainsString($passwordBaru, $outbox->TextDecoded);
        $this->assertStringContainsString('Penguji Password Test', $outbox->TextDecoded);
    }

    public function test_lupa_password_sukses_tahunlahir(): void
    {
        [$user, $asesorId] = $this->seedPenguji('md5', 'passlama');
        $noInduk = DB::table('asesor')->where('id', $asesorId)->value('no_induk');

        $res = $this->apiPost('/api/v1/auth/penguji/lupa-password', [
            'npm' => $noInduk,
            'pertanyaan' => 'tahunlahir',
            'jawaban' => '1990-05-17',
        ]);

        $res->assertStatus(200);
        $passwordBaru = $res->json('data.password_baru');

        // SMS memuat nama (regression fix typo $ds legacy)
        $outbox = DB::table('outbox')->orderByDesc('ID')->first();
        $this->assertStringContainsString('Yth. Penguji Password Test', $outbox->TextDecoded);
    }

    public function test_lupa_password_jawaban_salah_422(): void
    {
        [$user, $asesorId] = $this->seedPenguji();
        $noInduk = DB::table('asesor')->where('id', $asesorId)->value('no_induk');

        $res = $this->apiPost('/api/v1/auth/penguji/lupa-password', [
            'npm' => $noInduk,
            'pertanyaan' => 'nomorktp',
            'jawaban' => '00000000000000',
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Maaf Jawaban Anda Salah');
    }

    public function test_lupa_password_no_induk_tidak_ditemukan_404(): void
    {
        $this->apiPost('/api/v1/auth/penguji/lupa-password', [
            'npm' => 'TIDAK.ADA.'.uniqid(),
            'pertanyaan' => 'nomorktp',
            'jawaban' => 'x',
        ])->assertStatus(404)
            ->assertJsonPath('message', 'No. Induk tidak ditemukan');
    }

    public function test_lupa_password_no_induk_kosong_tidak_match(): void
    {
        // asesor dengan no_induk kosong tidak boleh ketemu via npm=''
        DB::table('asesor')->insert([
            'nama' => 'Tanpa Induk',
            'no_ktp' => 'TANPA-INDUK-'.uniqid(),
            'no_induk' => '',
            'no_hp' => '081111111111',
            'tgl_lahir' => '1985-01-01',
            'password' => md5('x'),
            'aktif' => 'Y',
        ]);

        $this->apiPost('/api/v1/auth/penguji/lupa-password', [
            'npm' => '   ',
            'pertanyaan' => 'nomorktp',
            'jawaban' => 'x',
        ])->assertStatus(422);   // required + trim → gagal validasi
    }

    public function test_lupa_password_rate_limit_429(): void
    {
        [$user, $asesorId] = $this->seedPenguji();
        $noInduk = DB::table('asesor')->where('id', $asesorId)->value('no_induk');

        $payload = [
            'npm' => $noInduk,
            'pertanyaan' => 'nomorktp',
            'jawaban' => 'salah-bruteforce',
        ];

        // 5 percobaan pertama → 422 (jawaban salah)
        for ($i = 0; $i < 5; $i++) {
            $this->apiPost('/api/v1/auth/penguji/lupa-password', $payload)->assertStatus(422);
        }

        // Percobaan ke-6 → 429 Too Many Requests
        $this->apiPost('/api/v1/auth/penguji/lupa-password', $payload)->assertStatus(429);
    }

    public function test_lupa_password_validasi_pertanyaan_invalid_422(): void
    {
        $this->apiPost('/api/v1/auth/penguji/lupa-password', [
            'npm' => 'REG.X',
            'pertanyaan' => 'namaibu',
            'jawaban' => 'x',
        ])->assertStatus(422);
    }
}
