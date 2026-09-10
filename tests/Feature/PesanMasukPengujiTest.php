<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature test SMS Notifikasi / Pesan Masuk (portal penguji)
 * sesuai docs/BACKEND_PESAN_MASUK.md — read-only gabungan
 * sentitems + outbox by no_hp.
 *
 * Semua penulisan DB dibungkus transaksi test yang di-rollback.
 */
class PesanMasukPengujiTest extends TestCase
{
    private string $noHp = '0812999888777';

    private int $asesorId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();

        $this->asesorId = (int) DB::table('asesor')->insertGetId([
            'nama' => 'Penguji SMS Test',
            'no_ktp' => 'SMS-TEST-'.uniqid(),
            'no_hp' => $this->noHp,
            'aktif' => 'Y',
        ]);
        $noKtp = DB::table('asesor')->where('id', $this->asesorId)->value('no_ktp');

        $this->user = User::create([
            'username' => $noKtp,
            'nama_lengkap' => 'Penguji SMS Test',
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

    /**
     * sentitems: composite PK (ID, SequencePosition) + kolom TEXT NOT NULL
     * (Text, UDH, SenderID, CreatorID) → insert eksplisit.
     */
    private function seedSent(string $status, string $waktu, ?string $tujuan = null): void
    {
        $isi = "SMS {$status} pada {$waktu}";
        DB::table('sentitems')->insert([
            'ID' => random_int(900000, 999999),
            'SequencePosition' => 1,
            'SendingDateTime' => $waktu,
            'DestinationNumber' => $tujuan ?? $this->noHp,
            'Text' => $isi,
            'UDH' => '',
            'SenderID' => 'MyPhone1',
            'TextDecoded' => $isi,
            'Status' => $status,
            'CreatorID' => 'MyPhone1',
        ]);
    }

    private function seedOutbox(string $waktuInsert, ?string $tujuan = null): int
    {
        // Zero-date ditolak MySQL strict mode (NO_ZERO_DATE) → relax sementara
        if (str_starts_with($waktuInsert, '0000-00-00')) {
            $id = random_int(900000, 999999);
            $this->denganSqlModeRelaxed(function () use ($id, $tujuan, $waktuInsert) {
                DB::statement(
                    "INSERT INTO outbox (ID, InsertIntoDB, DestinationNumber, TextDecoded, CreatorID)
                     VALUES (?, '0000-00-00 00:00:00', ?, ?, ?)",
                    [$id, $tujuan ?? $this->noHp, "Antrian masuk {$waktuInsert}", 'api-laravel']
                );
            });

            return $id;
        }

        return (int) DB::table('outbox')->insertGetId([
            'ID' => random_int(900000, 999999),
            'InsertIntoDB' => $waktuInsert,
            'DestinationNumber' => $tujuan ?? $this->noHp,
            'TextDecoded' => "Antrian masuk {$waktuInsert}",
            'CreatorID' => 'api-laravel',
        ]);
    }

    /**
     * Jalankan callback dgn sql_mode relaxed (utk data legacy invalid:
     * zero-date & enum di luar daftar) — selalu restore.
     */
    private function denganSqlModeRelaxed(callable $fn): void
    {
        $original = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        try {
            DB::statement('SET SESSION sql_mode = \'\'');
            $fn();
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$original]);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // TESTS
    // ════════════════════════════════════════════════════════════════

    public function test_index_gabungan_sent_dan_queue(): void
    {
        $this->seedSent('DeliveryOK', '2026-09-10 10:00:00');
        $this->seedSent('SendingError', '2026-09-09 09:00:00');
        $this->seedSent('DeliveryPending', '2026-09-08 08:00:00');
        $this->seedOutbox('2026-09-11 12:00:00');
        $this->seedOutbox('2026-09-11 13:00:00');

        $res = $this->apiGet('/api/v1/auth/penguji/pesan-masuk', $this->token());

        $res->assertStatus(200)->assertJsonPath('success', true);
        $data = $res->json('data');
        $this->assertEquals(5, $data['jumlah_pesan']);

        // Urutan waktu DESC: outbox terbaru dulu
        $waktuList = array_column($data['pesan'], 'waktu');
        $sorted = $waktuList;
        rsort($sorted);
        $this->assertEquals($sorted, $waktuList);

        // Mapping status sentitems
        $byStatus = array_column($data['pesan'], null, 'status');
        $this->assertEquals('Terkirim', $byStatus['DeliveryOK']['status_label']);
        $this->assertEquals('hijau', $byStatus['DeliveryOK']['status_warna']);
        $this->assertEquals('Gagal Terkirim', $byStatus['SendingError']['status_label']);
        $this->assertEquals('merah', $byStatus['SendingError']['status_warna']);
        $this->assertEquals('Tertahan', $byStatus['DeliveryPending']['status_label']);
        $this->assertEquals('kuning', $byStatus['DeliveryPending']['status_warna']);
        $this->assertEquals('sent', $byStatus['DeliveryOK']['sumber']);

        // Outbox → Menunggu
        $queue = array_values(array_filter($data['pesan'], fn ($p) => $p['sumber'] === 'queue'));
        $this->assertCount(2, $queue);
        $this->assertEquals('Waiting', $queue[0]['status']);
        $this->assertEquals('Menunggu', $queue[0]['status_label']);
        $this->assertEquals('merah', $queue[0]['status_warna']);

        // Ringkasan
        $this->assertEquals(1, $data['ringkasan']['terkirim']);
        $this->assertEquals(1, $data['ringkasan']['gagal']);
        $this->assertEquals(2, $data['ringkasan']['menunggu']);
        $this->assertEquals(1, $data['ringkasan']['lainnya']);   // DeliveryPending
    }

    public function test_status_default_case(): void
    {
        $this->seedSent('DeliveryOK', '2026-09-10 10:00:00');
        // Paksa Status enum-invalid (melewati validasi enum MySQL — strict mode)
        $this->denganSqlModeRelaxed(function () {
            DB::statement("UPDATE sentitems SET Status = 'StatusAneh' WHERE DestinationNumber = ?", [$this->noHp]);
        });

        $res = $this->apiGet('/api/v1/auth/penguji/pesan-masuk', $this->token());
        $pesan = $res->json('data.pesan.0');
        $this->assertEquals('Status tidak diketahui', $pesan['status_label']);
        $this->assertEquals('biru', $pesan['status_warna']);
    }

    public function test_normalisasi_nomor_62(): void
    {
        // SMS terkirim pakai format 62xxx (0812999888777 → 62812999888777)
        // — tetap harus match
        $this->seedSent('DeliveryOK', '2026-09-10 10:00:00', '62812999888777');
        $this->seedOutbox('2026-09-11 12:00:00');   // format 08xx

        $res = $this->apiGet('/api/v1/auth/penguji/pesan-masuk', $this->token());
        $data = $res->json('data');
        $this->assertEquals(2, $data['jumlah_pesan']);
    }

    public function test_zero_date_outbox_null(): void
    {
        // Pola live: semua timestamp outbox zero-date (daemon mati)
        $this->seedOutbox('0000-00-00 00:00:00');

        $res = $this->apiGet('/api/v1/auth/penguji/pesan-masuk', $this->token());
        $pesan = $res->json('data.pesan.0');
        $this->assertNull($pesan['waktu']);
        $this->assertEquals('Menunggu', $pesan['status_label']);
        $this->assertEquals(1, $res->json('data.ringkasan.menunggu'));
    }

    public function test_asesor_tanpa_no_hp_kosong(): void
    {
        DB::table('asesor')->where('id', $this->asesorId)->update(['no_hp' => null]);
        // refresh user no-ktp lookup tetap sama

        $res = $this->apiGet('/api/v1/auth/penguji/pesan-masuk', $this->token());
        $res->assertStatus(200);
        $this->assertEquals(0, $res->json('data.jumlah_pesan'));
        $this->assertEmpty($res->json('data.pesan'));
    }

    public function test_limit_param(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedOutbox('2026-09-01 0'.$i.':00:00');
        }

        // Default: semua
        $res = $this->apiGet('/api/v1/auth/penguji/pesan-masuk', $this->token());
        $this->assertEquals(5, $res->json('data.jumlah_pesan'));

        // ?limit=2
        $res2 = $this->apiGet('/api/v1/auth/penguji/pesan-masuk?limit=2', $this->token());
        $this->assertEquals(2, $res2->json('data.jumlah_pesan'));
        // Terbaru dulu
        $this->assertEquals('2026-09-01 05:00:00', $res2->json('data.pesan.0.waktu'));
    }

    public function test_non_penguji_403(): void
    {
        $userAdmin = User::create([
            'username' => 'admin-sms-'.uniqid(),
            'password' => Hash::make('test123'),
            'level' => 'admin',
            'blokir' => 'N',
        ]);

        $this->apiGet('/api/v1/auth/penguji/pesan-masuk', $userAdmin->createToken('t')->plainTextToken)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akses ditolak. Halaman ini khusus penguji.');
    }

    public function test_unauthenticated_401(): void
    {
        $this->apiGet('/api/v1/auth/penguji/pesan-masuk')->assertStatus(401);
    }
}
