# Swagger/OpenAPI Documentation untuk API Pendaftaran

## URL Swagger Documentation

Karena L5-Swagger mengalami kendala instalasi, dokumentasi API tersedia dalam format berikut:

1. **OpenAPI 3.0 YAML**: `/docs/swagger-pendaftaran.yaml`
2. **HTML Preview**: Buka file ini dengan Swagger Editor

## Cara Preview Documentation

### Option 1: Swagger Editor Online
1. Buka [https://editor.swagger.io/](https://editor.swagger.io/)
2. Copy-paste isi file `/docs/swagger-pendaftaran.yaml`
3. Documentation akan muncul secara interaktif

### Option 2: ReDoc
1. Buka [https://redocly.github.io/redoc/](https://redocly.github.io/redoc/)
2. Paste content YAML tersebut
3. Documentation akan muncul dengan format yang berbeda

### Option 3: Install L5-Swagger Manual

Jika ingin menginstall L5-Swagger, coba cara berikut:

```bash
# Download manual
wget https://github.com/DarkaOnline/L5-Swagger/archive/refs/heads/8.x.zip

# Extract ke vendor
unzip 8.x.zip
mkdir -p vendor/darkaonline
mv L5-Swagger-8.x vendor/darkaonline/l5-swagger

# Publish config
php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider"

# Generate docs
php artisan l5-swagger:generate
```

## API Endpoints Summary

### Public Endpoints (Tanpa Authentication)

#### 1. POST /api/v1/pendaftaran
Submit pendaftaran baru dengan auto-generated nomor pendaftaran dan password.

**Request:**
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
  "wil_ujikom": "Jakarta",
  "nama_institusi": "PT. Contoh",
  "jabatan": "Manager",
  "alamat_kantor": "Jl. Kantor No. 123",
  "kode_pos": "10110"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Pendaftaran berhasil!",
  "data": {
    "no_pendaftaran": "REG-20240820-0001",
    "nama": "Budi Santoso",
    "email": "budi@example.com",
    "password": "AbC123!@#xyZ",
    "status": "PENDING"
  }
}
```

#### 2. GET /api/v1/pendaftaran/{no_pendaftaran}
Cek status pendaftaran.

**Response:**
```json
{
  "success": true,
  "data": {
    "no_pendaftaran": "REG-20240820-0001",
    "nama": "Budi Santoso",
    "status": "PENDING"
  }
}
```

### Protected Endpoints (Require Bearer Token)

#### 3. GET /api/v1/pendaftaran
Get semua pendaftaran dengan filter & pagination.

**Query Params:**
- `status`: PENDING, DIVERIFIKASI, DISETUJUI, DITOLAK
- `search`: Search keyword
- `per_page`: Items per page (default: 15)

#### 4. GET /api/v1/pendaftaran/statistics
Get statistik pendaftaran.

**Response:**
```json
{
  "success": true,
  "data": {
    "total": 100,
    "pending": 25,
    "diverifikasi": 15,
    "disetujui": 50,
    "ditolak": 10
  }
}
```

#### 5. PUT /api/v1/pendaftaran/{id}/status
Update status pendaftaran.

**Request:**
```json
{
  "status": "DISETUJUI",
  "catatan": "Dokumen lengkap"
}
```

#### 6. DELETE /api/v1/pendaftaran/{id}
Soft delete pendaftaran.

## Authentication

Untuk protected endpoints, gunakan Bearer Token:

```
Authorization: Bearer {sanctum_token}
```

## Error Responses

### 422 Validation Error
```json
{
  "success": false,
  "message": "Validasi gagal",
  "errors": {
    "email": ["Email sudah terdaftar"],
    "no_ktp": ["Nomor KTP harus 16 karakter"]
  }
}
```

### 404 Not Found
```json
{
  "success": false,
  "message": "Data tidak ditemukan"
}
```

### 500 Server Error
```json
{
  "success": false,
  "message": "Terjadi kesalahan",
  "error": "Error detail"
}
```

## Testing dengan cURL

### Test Submit Pendaftaran
```bash
curl -X POST http://127.0.0.1:8000/api/v1/pendaftaran \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "nama": "Test User",
    "email": "test@example.com",
    "no_hp": "081234567890",
    "no_ktp": "1234567890123456",
    "kebangsaan": "Indonesia",
    "kualifikasi_pendidikan": "S1",
    "bidang_keahlian": "Teknik Lingkungan",
    "alamat": "Jl. Test",
    "wil_ujikom": "Jakarta",
    "nama_institusi": "PT Test",
    "jabatan": "Staff",
    "alamat_kantor": "Jl. Kantor",
    "kode_pos": "12345"
  }'
```

### Test Cek Status
```bash
curl http://127.0.0.1:8000/api/v1/pendaftaran/REG-20240820-0001
```

## Frontend Integration Example

### React/Fetch
```javascript
const submitPendaftaran = async (formData) => {
  const response = await fetch('http://127.0.0.1:8000/api/v1/pendaftaran', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(formData),
  });

  const data = await response.json();

  if (data.success) {
    console.log('No Pendaftaran:', data.data.no_pendaftaran);
    console.log('Password:', data.data.password);
  }

  return data;
};
```

### Axios
```javascript
import axios from 'axios';

const submitPendaftaran = async (formData) => {
  try {
    const response = await axios.post(
      'http://127.0.0.1:8000/api/v1/pendaftaran',
      formData,
      {
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
      }
    );

    console.log('No Pendaftaran:', response.data.data.no_pendaftaran);
    return response.data;
  } catch (error) {
    console.error('Error:', error.response.data);
  }
};
```

## Status Flow Diagram

```
PENDING → DIVERIFIKASI → DISETUJUI
                      ↘ DITOLAK
```

## Notes

- Semua timestamps dalam format: `d M Y H:i` (Contoh: `20 Aug 2024 13:21`)
- Password yang digenerate otomatis 12 karakter aman
- No Pendaftaran format: `REG-YYYYMMDD-XXXX`
- Email dan No KTP harus unik
- Support pagination untuk list endpoints
- Soft delete untuk data (tidak dihapus permanen)
