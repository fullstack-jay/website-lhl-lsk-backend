# Backend Documentation - Layanan Pengaduan Admin (Laravel)

## Overview
Dokumentasi ini menjelaskan implementasi backend Laravel untuk menu **Layanan Pengaduan** di panel Admin.

---

## 1. Database Migration

### Tabel Pengaduan

```php
// database/migrations/xxxx_xx_xx_create_pengaduans_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaduans', function (Blueprint $table) {
            $table->id();
            $table->string('no_pengaduan', 20)->unique(); // Format: ADU-YYYYMM-XXX
            $table->date('tanggal');
            $table->time('waktu');

            // Informasi pengirim
            $table->string('nama');
            $table->string('email')->nullable();
            $table->string('no_hp');
            $table->enum('jenis_responden', ['peserta', 'penguji', 'masyarakat']);

            // Konten pengaduan
            $table->text('aduan');

            // Lampiran (opsional)
            $table->string('lampiran')->nullable();

            // Status
            $table->enum('status', ['waiting', 'processing', 'completed', 'archived'])
                  ->default('waiting');

            // Respon admin
            $table->text('respon_admin')->nullable();
            $table->text('catatan_internal')->nullable();

            // Metadata
            $table->boolean('dibaca')->default(false);
            $table->string('dibaca_oleh')->nullable();
            $table->timestamp('dibaca_tanggal')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaduans');
    }
};
```

### Tabel Riwayat Respon

```php
// database/migrations/xxxx_xx_xx_create_riwayat_ponses_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riwayat_ponses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengaduan_id')->constrained()->onDelete('cascade');
            $table->date('tanggal');
            $table->time('waktu');
            $table->string('admin');
            $table->text('isi');
            $table->string('lampiran')->nullable();
            $table->timestamps();

            $table->index('pengaduan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_ponses');
    }
};
```

---

## 2. Model

### Pengaduan Model

```php
// app/Models/Pengaduan.php

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pengaduan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'no_pengaduan',
        'tanggal',
        'waktu',
        'nama',
        'email',
        'no_hp',
        'jenis_responden',
        'aduan',
        'lampiran',
        'status',
        'respon_admin',
        'catatan_internal',
        'dibaca',
        'dibaca_oleh',
        'dibaca_tanggal',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'waktu' => 'datetime:H:i',
        'dibaca' => 'boolean',
        'dibaca_tanggal' => 'datetime',
    ];

    /**
     * Generate nomor pengaduan otomatis
     * Format: ADU-YYYYMM-XXX
     */
    public static function generateNomorPengaduan(): string
    {
        $yearMonth = now()->format('Ym');
        $count = self::whereYear('created_at', now()->year)
                     ->whereMonth('created_at', now()->month)
                     ->count() + 1;

        return 'ADU-' . $yearMonth . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Relationship dengan riwayat respon
     */
    public function riwayatRespon()
    {
        return $this->hasMany(RiwayatRespon::class, 'pengaduan_id');
    }

    /**
     * Scope untuk filter status
     */
    public function scopeStatus($query, $status)
    {
        if ($status && $status !== 'all') {
            return $query->where('status', $status);
        }
        return $query;
    }

    /**
     * Scope untuk search
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('no_hp', 'like', "%{$search}%")
                  ->orWhere('aduan', 'like', "%{$search}%")
                  ->orWhere('no_pengaduan', 'like', "%{$search}%");
            });
        }
        return $query;
    }

    /**
     * Scope untuk filter jenis responden
     */
    public function scopeJenisResponden($query, $jenis)
    {
        if ($jenis) {
            return $query->where('jenis_responden', $jenis);
        }
        return $query;
    }

    /**
     * Scope untuk filter tanggal
     */
    public function scopeTanggalRange($query, $startDate, $endDate)
    {
        if ($startDate && $endDate) {
            return $query->whereBetween('tanggal', [$startDate, $endDate]);
        }
        return $query;
    }
}
```

### RiwayatRespon Model

```php
// app/Models/RiwayatRespon.php

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RiwayatRespon extends Model
{
    use HasFactory;

    protected $fillable = [
        'pengaduan_id',
        'tanggal',
        'waktu',
        'admin',
        'isi',
        'lampiran',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'waktu' => 'datetime:H:i',
    ];

    /**
     * Relationship dengan pengaduan
     */
    public function pengaduan()
    {
        return $this->belongsTo(Pengaduan::class);
    }
}
```

---

## 3. Controller

```php
// app/Http/Controllers/Admin/PengaduanController.php

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pengaduan;
use App\Models\RiwayatRespon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PengaduanController extends Controller
{
    /**
     * Get list pengaduan dengan filter & pagination
     * GET /api/v1/admin/pengaduan
     */
    public function index(Request $request)
    {
        $query = Pengaduan::query();

        // Filter status
        $query->status($request->status);

        // Search
        $query->search($request->search);

        // Filter jenis responden
        $query->jenisResponden($request->jenis_responden);

        // Filter tanggal
        $query->tanggalRange($request->start_date, $request->end_date);

        // Sorting
        $sort = $request->sort ?? 'created_at';
        $order = $request->order ?? 'DESC';
        $query->orderBy($sort, $order);

        // Pagination
        $perPage = min($request->per_page ?? 20, 100);
        $result = $query->paginate($perPage);

        // Get counts by status
        $counts = [
            'all' => Pengaduan::count(),
            'waiting' => Pengaduan::where('status', 'waiting')->count(),
            'processing' => Pengaduan::where('status', 'processing')->count(),
            'completed' => Pengaduan::where('status', 'completed')->count(),
            'archived' => Pengaduan::where('status', 'archived')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $result->items(),
            'pagination' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
                'from' => $result->firstItem(),
                'to' => $result->lastItem(),
            ],
            'counts' => $counts,
        ]);
    }

    /**
     * Get detail pengaduan
     * GET /api/v1/admin/pengaduan/{id}
     */
    public function show($id)
    {
        $pengaduan = Pengaduan::with(['riwayatRespon' => function($query) {
            $query->orderBy('created_at', 'asc');
        }])->findOrFail($id);

        // Mark as read
        if (!$pengaduan->dibaca) {
            $pengaduan->update([
                'dibaca' => true,
                'dibaca_oleh' => auth()->user()->name,
                'dibaca_tanggal' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $pengaduan,
        ]);
    }

    /**
     * Submit respon pengaduan
     * POST /api/v1/admin/pengaduan/{id}/respon
     */
    public function respon(Request $request, $id)
    {
        $pengaduan = Pengaduan::findOrFail($id);

        $request->validate([
            'tanggapan' => 'required|min:5',
            'catatan_internal' => 'nullable|max:1000',
            'status' => 'required|in:waiting,processing,completed,archived',
            'kirim_notifikasi' => 'boolean',
        ]);

        // Update pengaduan
        $pengaduan->update([
            'respon_admin' => $request->tanggapan,
            'catatan_internal' => $request->catatan_internal,
            'status' => $request->status,
        ]);

        // Add to riwayat
        RiwayatRespon::create([
            'pengaduan_id' => $pengaduan->id,
            'tanggal' => now()->toDateString(),
            'waktu' => now()->format('H:i'),
            'admin' => auth()->user()->name,
            'isi' => $request->tanggapan,
        ]);

        // Kirim notifikasi jika diminta
        if ($request->kirim_notifikasi && $pengaduan->email) {
            // Implementasi kirim email/SMS
            // Mail::to($pengaduan->email)->send(new PengaduanResponMail($pengaduan));
        }

        return response()->json([
            'success' => true,
            'message' => 'Respon berhasil dikirim',
            'data' => $pengaduan->fresh()->load('riwayatRespon'),
        ]);
    }

    /**
     * Update status pengaduan
     * PUT /api/v1/admin/pengaduan/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $pengaduan = Pengaduan::findOrFail($id);

        $request->validate([
            'status' => 'required|in:waiting,processing,completed,archived',
            'catatan' => 'nullable|max:500',
        ]);

        $statusLama = $pengaduan->status;
        $pengaduan->update([
            'status' => $request->status,
        ]);

        // Add catatan to riwayat if provided
        if ($request->catatan) {
            RiwayatRespon::create([
                'pengaduan_id' => $pengaduan->id,
                'tanggal' => now()->toDateString(),
                'waktu' => now()->format('H:i'),
                'admin' => auth()->user()->name,
                'isi' => "Status diubah dari {$statusLama} menjadi {$request->status}. Catatan: {$request->catatan}",
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Status berhasil diupdate',
            'data' => [
                'id' => $pengaduan->id,
                'no_pengaduan' => $pengaduan->no_pengaduan,
                'status_lama' => $statusLama,
                'status_baru' => $pengaduan->status,
            ],
        ]);
    }

    /**
     * Delete/Archive pengaduan
     * DELETE /api/v1/admin/pengaduan/{id}
     */
    public function destroy(Request $request, $id)
    {
        $pengaduan = Pengaduan::withTrashed()->findOrFail($id);

        $type = $request->type ?? 'soft';

        if ($type === 'hard') {
            // Permanent delete
            $pengaduan->forceDelete();
            $message = 'Pengaduan berhasil dihapus permanen';
        } else {
            // Soft delete (archive)
            $pengaduan->update(['status' => 'archived']);
            $pengaduan->delete();
            $message = 'Pengaduan berhasil diarsipkan';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Get counts by status
     * GET /api/v1/admin/pengaduan/counts
     */
    public function counts()
    {
        $counts = [
            'all' => Pengaduan::count(),
            'waiting' => Pengaduan::where('status', 'waiting')->count(),
            'processing' => Pengaduan::where('status', 'processing')->count(),
            'completed' => Pengaduan::where('status', 'completed')->count(),
            'archived' => Pengaduan::where('status', 'archived')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $counts,
        ]);
    }
}
```

---

## 4. Routes

```php
// routes/api.php

use App\Http\Controllers\Admin\PengaduanController;

// Admin routes (require authentication & admin role)
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    // Pengaduan routes
    Route::prefix('pengaduan')->group(function () {
        Route::get('/', [PengaduanController::class, 'index'])
            ->name('admin.pengaduan.index');

        Route::get('/counts', [PengaduanController::class, 'counts'])
            ->name('admin.pengaduan.counts');

        Route::get('/{id}', [PengaduanController::class, 'show'])
            ->name('admin.pengaduan.show');

        Route::post('/{id}/respon', [PengaduanController::class, 'respon'])
            ->name('admin.pengaduan.respon');

        Route::put('/{id}/status', [PengaduanController::class, 'updateStatus'])
            ->name('admin.pengaduan.updateStatus');

        Route::delete('/{id}', [PengaduanController::class, 'destroy'])
            ->name('admin.pengaduan.destroy');
    });
});
```

---

## 5. API Endpoints Summary

### 1. List Pengaduan
```
GET /api/v1/admin/pengaduan
Authorization: Bearer {token}

Query Parameters:
  - status: waiting | processing | completed | archived | all
  - search: string (search in nama, email, no_hp, aduan, no_pengaduan)
  - jenis_responden: peserta | penguji | masyarakat
  - start_date: YYYY-MM-DD
  - end_date: YYYY-MM-DD
  - page: int (default: 1)
  - per_page: int (default: 20, max: 100)
  - sort: created_at | tanggal | nama | status
  - order: ASC | DESC

Response (200):
{
  "success": true,
  "data": [...],
  "pagination": {...},
  "counts": {
    "all": 50,
    "waiting": 10,
    "processing": 15,
    "completed": 20,
    "archived": 5
  }
}
```

### 2. Get Detail
```
GET /api/v1/admin/pengaduan/{id}

Response (200):
{
  "success": true,
  "data": {
    "id": 1,
    "no_pengaduan": "ADU-202501-001",
    "tanggal": "2025-01-10",
    "waktu": "10:30",
    "nama": "Ahmad Rizky",
    "email": "ahmad@email.com",
    "no_hp": "081234567890",
    "jenis_responden": "peserta",
    "aduan": "...",
    "lampiran": null,
    "status": "waiting",
    "respon_admin": null,
    "catatan_internal": null,
    "dibaca": true,
    "dibaca_oleh": "Administrator",
    "dibaca_tanggal": "2025-01-10T11:00:00Z",
    "riwayat": [...],
    "created_at": "...",
    "updated_at": "..."
  }
}
```

### 3. Submit Respon
```
POST /api/v1/admin/pengaduan/{id}/respon

Request Body:
{
  "tanggapan": "string (required, min 5)",
  "catatan_internal": "string (optional, max 1000)",
  "status": "waiting|processing|completed|archived",
  "kirim_notifikasi": "boolean"
}

Response (200):
{
  "success": true,
  "message": "Respon berhasil dikirim",
  "data": {...}
}
```

### 4. Update Status
```
PUT /api/v1/admin/pengaduan/{id}/status

Request Body:
{
  "status": "processing",
  "catatan": "string (optional, max 500)"
}

Response (200):
{
  "success": true,
  "message": "Status berhasil diupdate",
  "data": {
    "id": 1,
    "no_pengaduan": "ADU-202501-001",
    "status_lama": "waiting",
    "status_baru": "processing"
  }
}
```

### 5. Delete/Archive
```
DELETE /api/v1/admin/pengaduan/{id}?type=soft|hard

Response (200):
{
  "success": true,
  "message": "Pengaduan berhasil diarsipkan"
}
```

---

## 6. Frontend Features yang Didukung

1. **Status Tabs**: Filter by status (Semua, Menunggu, Diproses, Selesai, Diarsipkan)
2. **Search**: Search by nama, email, no_hp, aduan, no_pengaduan
3. **Filter**: By jenis responden, date range
4. **Pagination**: 20 items per page (configurable)
5. **Sort**: By tanggal, nama, status
6. **Detail Modal**: View full pengaduan with riwayat
7. **Respon Form**: Submit tanggapan with catatan internal
8. **Status Update**: Quick status change
9. **Delete**: Soft delete (archive) or hard delete

---

## 7. Status Flow

```
waiting → processing → completed
   ↓           ↓          ↓
archived    archived   archived
```

- **waiting**: Pengaduan baru masuk, belum diproses
- **processing**: Sedang ditangani oleh admin
- **completed**: Sudah selesai ditangani
- **archived**: Ditutup/diarsipkan

---

## 8. Testing dengan cURL

```bash
# Get list (with token)
TOKEN="your_token_here"
curl -X GET "http://127.0.0.1:8000/api/v1/admin/pengaduan?status=waiting&page=1" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"

# Get detail
curl -X GET "http://127.0.0.1:8000/api/v1/admin/pengaduan/1" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"

# Submit respon
curl -X POST "http://127.0.0.1:8000/api/v1/admin/pengaduan/1/respon" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "tanggapan": "Terima kasih atas laporannya. Sedang kami proses.",
    "catatan_internal": "Follow up dengan tim terkait",
    "status": "processing",
    "kirim_notifikasi": true
  }'
```

---

## 9. Notes

1. **Nomor Pengaduan**: Auto-generated format `ADU-YYYYMM-XXX`
2. **Soft Delete**: Menggunakan `deleted_at` column untuk arsip
3. **Read Status**: Track siapa yang membaca dan kapan
4. **Riwayat Respon**: Setiap respon tersimpan di riwayat untuk audit trail
5. **Notification**: Optional kirim notifikasi email/SMS saat respon