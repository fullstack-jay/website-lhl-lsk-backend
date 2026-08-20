# BACKEND PENDAFTARAN - Laravel Implementation

## Overview
Dokumentasi ini menjelaskan implementasi backend Laravel untuk form pendaftaran yang ada di frontend `http://localhost:3000/daftar`.

---

## 1. DATABASE SCHEMA (MySQL)

### 1.1 Migration: Create Pendaftaran Table

```php
// database/migrations/2024_08_20_000001_create_pendaftarans_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pendaftaran', function (Blueprint $table) {
            $table->id();
            $table->string('no_pendaftaran')->unique();
            
            // Informasi Pribadi
            $table->string('nama');
            $table->string('email');
            $table->string('no_hp', 14);
            $table->string('no_ktp', 16)->unique();
            $table->string('kebangsaan');
            $table->enum('kualifikasi_pendidikan', ['D4', 'S1', 'S2', 'S3']);
            $table->string('bidang_keahlian');
            
            // Alamat Lengkap
            $table->text('alamat');
            $table->string('propinsi')->nullable();
            $table->string('kota')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kelurahan')->nullable();
            
            // Lokasi Uji Kompetensi
            $table->string('wil_ujikom');
            
            // Data Pekerjaan Sekarang
            $table->string('nama_institusi');
            $table->string('jabatan');
            $table->text('alamat_kantor');
            $table->string('kode_pos', 5);
            $table->string('no_telp_kantor')->nullable();
            $table->string('no_fax_kantor')->nullable();
            $table->string('email_kantor')->nullable();
            
            // Status & Metadata
            $table->enum('status', ['PENDING', 'DIVERIFIKASI', 'DISETUJUI', 'DITOLAK'])->default('PENDING');
            $table->text('catatan')->nullable();
            $table->timestamp('tanggal_verifikasi')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            
            // Timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('status');
            $table->index('no_pendaftaran');
            $table->index('no_ktp');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pendaftaran');
    }
};
```

### 1.2 Optional: Create Provinces Table (Untuk Referensi Wilayah)

```php
// database/migrations/2024_08_20_000002_create_wilayah_tables.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Provinsi
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        // Kota/Kabupaten
        Schema::create('regencies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('province_code');
            $table->string('name');
            $table->foreign('province_code')->references('code')->on('provinces')->onDelete('cascade');
            $table->timestamps();
        });

        // Kecamatan
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('regency_code');
            $table->string('name');
            $table->foreign('regency_code')->references('code')->on('regencies')->onDelete('cascade');
            $table->timestamps();
        });

        // Kelurahan
        Schema::create('villages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('district_code');
            $table->string('name');
            $table->foreign('district_code')->references('code')->on('districts')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villages');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('regencies');
        Schema::dropIfExists('provinces');
    }
};
```

---

## 2. MODEL

```php
// app/Models/Pendaftaran.php

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pendaftaran extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'no_pendaftaran',
        // Informasi Pribadi
        'nama',
        'email',
        'no_hp',
        'no_ktp',
        'kebangsaan',
        'kualifikasi_pendidikan',
        'bidang_keahlian',
        // Alamat
        'alamat',
        'propinsi',
        'kota',
        'kecamatan',
        'kelurahan',
        // Lokasi Uji Kompetensi
        'wil_ujikom',
        // Data Pekerjaan
        'nama_institusi',
        'jabatan',
        'alamat_kantor',
        'kode_pos',
        'no_telp_kantor',
        'no_fax_kantor',
        'email_kantor',
        // Status
        'status',
        'catatan',
        'tanggal_verifikasi',
        'verified_by',
    ];

    protected $casts = [
        'tanggal_verifikasi' => 'datetime',
    ];

    /**
     * Generate nomor pendaftaran otomatis
     * Format: REG-YYYYMMDD-XXXX
     */
    public static function generateNoPendaftaran(): string
    {
        $date = now()->format('Ymd');
        $prefix = "REG-{$date}-";
        
        $lastPendaftaran = self::withTrashed()
            ->where('no_pendaftaran', 'like', $prefix . '%')
            ->orderBy('no_pendaftaran', 'desc')
            ->first();
        
        if ($lastPendaftaran) {
            $lastNumber = (int) substr($lastPendaftaran->no_pendaftaran, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Relationship dengan User (verifikator)
     */
    public function verifikator()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Scope untuk status tertentu
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeDisetujui($query)
    {
        return $query->where('status', 'DISETUJUI');
    }

    public function scopeDitolak($query)
    {
        return $query->where('status', 'DITOLAK');
    }
}
```

---

## 3. CONTROLLER

### 3.1 Pendaftaran Controller

```php
// app/Http/Controllers/api/PendaftaranController.php

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pendaftaran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PendaftaranController extends Controller
{
    /**
     * Store new pendaftaran
     * POST /api/pendaftaran
     */
    public function store(Request $request)
    {
        // Validation rules
        $validator = Validator::make($request->all(), [
            // Informasi Pribadi
            'nama' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:pendaftaran,email',
            'no_hp' => 'required|string|max:14|regex:/^[0-9]+$/',
            'no_ktp' => 'required|string|size:16|regex:/^[0-9]+$/|unique:pendaftaran,no_ktp',
            'kebangsaan' => 'required|string|max:100',
            'kualifikasi_pendidikan' => 'required|in:D4,S1,S2,S3',
            'bidang_keahlian' => 'required|string|max:255',
            
            // Alamat
            'alamat' => 'required|string',
            'propinsi' => 'nullable|string|max:100',
            'kota' => 'nullable|string|max:100',
            'kecamatan' => 'nullable|string|max:100',
            'kelurahan' => 'nullable|string|max:100',
            
            // Lokasi Uji Kompetensi
            'wil_ujikom' => 'required|string|max:100',
            
            // Data Pekerjaan
            'nama_institusi' => 'required|string|max:255',
            'jabatan' => 'required|string|max:255',
            'alamat_kantor' => 'required|string',
            'kode_pos' => 'required|string|max:5|regex:/^[0-9]+$/',
            'no_telp_kantor' => 'nullable|string|max:20',
            'no_fax_kantor' => 'nullable|string|max:20',
            'email_kantor' => 'nullable|email|max:255',
        ], [
            'required' => ':attribute wajib diisi',
            'email' => 'Format :attribute tidak valid',
            'unique' => ':attribute sudah terdaftar',
            'regex' => 'Format :attribute tidak valid',
            'in' => ':attribute harus salah satu dari: :values',
            'size' => ':attribute harus :size karakter',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Generate nomor pendaftaran
            $noPendaftaran = Pendaftaran::generateNoPendaftaran();

            // Create pendaftaran
            $pendaftaran = Pendaftaran::create([
                'no_pendaftaran' => $noPendaftaran,
                'nama' => $request->nama,
                'email' => $request->email,
                'no_hp' => $request->no_hp,
                'no_ktp' => $request->no_ktp,
                'kebangsaan' => $request->kebangsaan,
                'kualifikasi_pendidikan' => $request->kualifikasi_pendidikan,
                'bidang_keahlian' => $request->bidang_keahlian,
                'alamat' => $request->alamat,
                'propinsi' => $request->propinsi,
                'kota' => $request->kota,
                'kecamatan' => $request->kecamatan,
                'kelurahan' => $request->kelurahan,
                'wil_ujikom' => $request->wil_ujikom,
                'nama_institusi' => $request->nama_institusi,
                'jabatan' => $request->jabatan,
                'alamat_kantor' => $request->alamat_kantor,
                'kode_pos' => $request->kode_pos,
                'no_telp_kantor' => $request->no_telp_kantor,
                'no_fax_kantor' => $request->no_fax_kantor,
                'email_kantor' => $request->email_kantor,
                'status' => 'PENDING',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pendaftaran berhasil! Silakan cek email Anda untuk verifikasi.',
                'data' => [
                    'no_pendaftaran' => $pendaftaran->no_pendaftaran,
                    'nama' => $pendaftaran->nama,
                    'email' => $pendaftaran->email,
                    'status' => $pendaftaran->status,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses pendaftaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get pendaftaran by no_pendaftaran
     * GET /api/pendaftaran/{no_pendaftaran}
     */
    public function show($noPendaftaran)
    {
        $pendaftaran = Pendaftaran::where('no_pendaftaran', $noPendaftaran)->first();

        if (!$pendaftaran) {
            return response()->json([
                'success' => false,
                'message' => 'Data pendaftaran tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $pendaftaran,
        ]);
    }

    /**
     * Update status pendaftaran (untuk admin)
     * PUT /api/pendaftaran/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:DIVERIFIKASI,DISETUJUI,DITOLAK',
            'catatan' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $pendaftaran = Pendaftaran::findOrFail($id);
            
            $pendaftaran->update([
                'status' => $request->status,
                'catatan' => $request->catatan,
                'tanggal_verifikasi' => now(),
                'verified_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status pendaftaran berhasil diperbarui',
                'data' => $pendaftaran,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all pendaftaran (untuk admin)
     * GET /api/pendaftaran
     */
    public function index(Request $request)
    {
        $query = Pendaftaran::query();

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('no_pendaftaran', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Pagination
        $perPage = $request->input('per_page', 15);
        $pendaftaran = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $pendaftaran->items(),
            'pagination' => [
                'total' => $pendaftaran->total(),
                'per_page' => $pendaftaran->perPage(),
                'current_page' => $pendaftaran->currentPage(),
                'last_page' => $pendaftaran->lastPage(),
            ],
        ]);
    }

    /**
     * Delete pendaftaran
     * DELETE /api/pendaftaran/{id}
     */
    public function destroy($id)
    {
        try {
            $pendaftaran = Pendaftaran::findOrFail($id);
            $pendaftaran->delete();

            return response()->json([
                'success' => true,
                'message' => 'Data pendaftaran berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
```

---

## 4. ROUTES

```php
// routes/api.php

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PendaftaranController;

// Pendaftaran Routes
Route::prefix('pendaftaran')->group(function () {
    // Public route untuk submit form
    Route::post('/', [PendaftaranController::class, 'store']);
    
    // Cek status pendaftaran (public)
    Route::get('/{no_pendaftaran}', [PendaftaranController::class, 'show']);
    
    // Admin routes (perlu authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/', [PendaftaranController::class, 'index']);
        Route::put('/{id}/status', [PendaftaranController::class, 'updateStatus']);
        Route::delete('/{id}', [PendaftaranController::class, 'destroy']);
    });
});
```

---

## 5. FRONTEND API INTEGRATION

### 5.1 Update RegistrationForm.tsx handleSubmit

```typescript
// Update handleSubmit function di RegistrationForm.tsx

const handleSubmit = async (e: React.FormEvent) => {
  e.preventDefault();
  if (!validateForm()) return;

  setIsSubmitting(true);

  try {
    const response = await fetch('http://your-laravel-api.test/api/pendaftaran', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(formData),
    });

    const data = await response.json();

    if (data.success) {
      alert(`${data.message}\nNomor Pendaftaran: ${data.data.no_pendaftaran}`);
      // Reset form atau redirect
    } else {
      // Handle validation errors
      if (data.errors) {
        const newErrors: FormErrors = {};
        Object.keys(data.errors).forEach(key => {
          newErrors[key as keyof FormErrors] = data.errors[key][0];
        });
        setErrors(newErrors);
      } else {
        alert(data.message || 'Terjadi kesalahan');
      }
    }
  } catch (error) {
    console.error('Error submitting form:', error);
    alert('Gagal mengirim data. Silakan coba lagi.');
  } finally {
    setIsSubmitting(false);
  }
};
```

### 5.2 Create API Service (Optional)

```typescript
// src/services/pendaftaran.ts

export interface PendaftaranResponse {
  success: boolean;
  message: string;
  data?: {
    no_pendaftaran: string;
    nama: string;
    email: string;
    status: string;
  };
  errors?: Record<string, string[]>;
}

export const pendaftaranApi = {
  submit: async (formData: FormData): Promise<PendaftaranResponse> => {
    const response = await fetch('http://your-laravel-api.test/api/pendaftaran', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(formData),
    });
    return response.json();
  },

  checkStatus: async (noPendaftaran: string) => {
    const response = await fetch(`http://your-laravel-api.test/api/pendaftaran/${noPendaftaran}`);
    return response.json();
  },
};
```

---

## 6. SEEDER (Untuk Testing)

```php
// database/seeders/PendaftaranSeeder.php

<?php

namespace Database\Seeders;

use App\Models\Pendaftaran;
use Illuminate\Database\Seeder;

class PendaftaranSeeder extends Seeder
{
    public function run(): void
    {
        Pendaftaran::create([
            'no_pendaftaran' => 'REG-20240820-0001',
            'nama' => 'John Doe',
            'email' => 'john@example.com',
            'no_hp' => '081234567890',
            'no_ktp' => '1234567890123456',
            'kebangsaan' => 'Indonesia',
            'kualifikasi_pendidikan' => 'S1',
            'bidang_keahlian' => 'Teknik Lingkungan',
            'alamat' => 'Jl. Contoh No. 123',
            'propinsi' => 'DKI Jakarta',
            'kota' => 'Jakarta Selatan',
            'kecamatan' => 'Tebet',
            'kelurahan' => 'Tebet Barat',
            'wil_ujikom' => 'Jakarta',
            'nama_institusi' => 'PT. Lingkungan Bersih',
            'jabatan' => 'Environmental Engineer',
            'alamat_kantor' => 'Jl. Kantor No. 456',
            'kode_pos' => '12000',
            'no_telp_kantor' => '0211234567',
            'no_fax_kantor' => '0211234568',
            'email_kantor' => 'info@lingkungan.com',
            'status' => 'PENDING',
        ]);
    }
}
```

---

## 7. ENVIRONMENT CONFIGURATION

```env
# .env

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nama_database_anda
DB_USERNAME=database_user
DB_PASSWORD=database_password

# Untuk CORS (jika frontend dan backend beda domain)
SANCTUM_STATEFUL_DOMAINS=localhost:3000
```

---

## 8. TESTING DENGING POSTMAN/THUNDER CLIENT

### POST /api/pendaftaran

```json
{
  "nama": "Budi Santoso",
  "email": "budi@example.com",
  "no_hp": "081234567890",
  "no_ktp": "1234567890123456",
  "kebangsaan": "Indonesia",
  "kualifikasi_pendidikan": "S1",
  "bidang_keahlian": "Teknik Lingkungan",
  "alamat": "Jl. Merdeka No. 1",
  "propinsi": "DKI Jakarta",
  "kota": "Jakarta Pusat",
  "kecamatan": "Gambir",
  "kelurahan": "Gambir",
  "wil_ujikom": "Jakarta",
  "nama_institusi": "PT. Contoh",
  "jabatan": "Manager",
  "alamat_kantor": "Jl. Kantor No. 123",
  "kode_pos": "10110",
  "no_telp_kantor": "02112345678",
  "no_fax_kantor": "02187654321",
  "email_kantor": "kantor@example.com"
}
```

### Response Success (201)

```json
{
  "success": true,
  "message": "Pendaftaran berhasil! Silakan cek email Anda untuk verifikasi.",
  "data": {
    "no_pendaftaran": "REG-20240820-0001",
    "nama": "Budi Santoso",
    "email": "budi@example.com",
    "status": "PENDING"
  }
}
```

### Response Validation Error (422)

```json
{
  "success": false,
  "message": "Validasi gagal",
  "errors": {
    "email": ["Email sudah terdaftar"],
    "no_ktp": ["Nomor KTP sudah terdaftar"]
  }
}
```

---

## 9. FITUR TAMBAHAN (OPSIONAL)

### 9.1 Email Notification

```php
// app/Notifications/PendaftaranSuccessNotification.php

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class PendaftaranSuccessNotification extends Notification
{
    use Queueable;

    protected $pendaftaran;

    public function __construct($pendaftaran)
    {
        $this->pendaftaran = $pendaftaran;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Pendaftaran Berhasil - LHK sertifikasi')
            ->greeting('Halo ' . $this->pendaftaran->nama . ',')
            ->line('Pendaftaran Anda telah berhasil kami terima.')
            ->line('Nomor Pendaftaran: ' . $this->pendaftaran->no_pendaftaran)
            ->line('Status: ' . $this->pendaftaran->status)
            ->action('Cek Status', url('/pendaftaran/' . $this->pendaftaran->no_pendaftaran))
            ->line('Terima kasih telah mendaftar.');
    }
}
```

### 9.2 WhatsApp Notification (Optional)

```php
// app/Services/WhatsAppService.php

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsAppService
{
    protected $apiUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('services.whatsapp.url');
        $this->apiKey = config('services.whatsapp.api_key');
    }

    public function sendMessage($phoneNumber, $message)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
        ])->post($this->apiUrl, [
            'phone' => $this->formatPhoneNumber($phoneNumber),
            'message' => $message,
        ]);

        return $response->successful();
    }

    private function formatPhoneNumber($phone)
    {
        // Format nomor HP untuk WhatsApp (62...)
        $phone = preg_replace('/^0/', '62', $phone);
        return $phone;
    }
}
```

---

## 10. DEPLOYMENT CHECKLIST

- [ ] Setup database MySQL di production
- [ ] Configure CORS untuk frontend domain
- [ ] Setup queue worker untuk email notifications
- [ ] Configure storage untuk file uploads (jika ada)
- [ ] Setup SSL certificate
- [ ] Configure rate limiting untuk prevent spam
- [ ] Setup backup database
- [ ] Configure logging dan monitoring

---

## SUMMARY

Backend Laravel untuk form pendaftaran mencakup:

1. **Database Schema**: Migration dengan proper indexes dan constraints
2. **Model**: Pendaftaran dengan auto-generation nomor pendaftaran
3. **Controller**: CRUD operations dengan proper validation
4. **Routes**: API endpoints untuk frontend integration
5. **Frontend Integration**: API call example untuk React
6. **Optional Features**: Email notifications, WhatsApp service

Flow lengkap:
1. User isi form di `http://localhost:3000/daftar`
2. Frontend POST ke Laravel API `/api/pendaftaran`
3. Laravel validasi dan simpan ke database
4. Generate nomor pendaftaran otomatis
5. Return response dengan nomor pendaftaran
6. (Optional) Kirim email/WhatsApp konfirmasi
