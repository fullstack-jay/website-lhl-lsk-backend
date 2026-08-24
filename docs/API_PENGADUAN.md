# API Dokumentasi - Layanan Pengaduan

## Base URL
```
http://localhost:8000/api/v1
```

---

## Public Endpoints

### 1. Submit Pengaduan (Public)

**Endpoint:** `POST /pengaduan`

**Description:** Submit pengaduan baru dari form publik

**Request Body:**
```json
{
  "nama": "string (required, min 3 karakter)",
  "email": "string (optional, valid email)",
  "no_hp": "string (optional, format nomor HP)",
  "jenis_responden": "string (required) - pilih: peserta | penguji | masyarakat",
  "aduan": "string (required, min 10 karakter)",
  "lampiran": "file (optional, PDF/JPG/PNG, max 2MB)"
}
```

**Response (201):**
```json
{
  "success": true,
  "message": "Pengaduan berhasil dikirim",
  "data": {
    "id": 67,
    "no_pengaduan": "ADU-202608-001",
    "nama": "Test User",
    "aduan": "Isi pengaduan...",
    "status": "waiting",
    "tanggal": "2026-08-20"
  }
}
```

---

## Admin Endpoints (Require Authentication)

Semua endpoint admin memerlukan header:
```
Authorization: Bearer {token}
```

### 2. List Pengaduan

**Endpoint:** `GET /admin/pengaduan`

**Query Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| status | string | all | Filter: waiting | processing | completed | archived |
| search | string | - | Search in: nama, email, no_hp, aduan, no_pengaduan |
| jenis_responden | string | - | Filter: peserta | penguji | masyarakat |
| start_date | string (Y-m-d) | - | Filter start date (tanggal) |
| end_date | string (Y-m-d) | - | Filter end date (tanggal) |
| page | int | 1 | Page number |
| per_page | int | 20 | Items per page (max 100) |
| sort | string | created_at | Sort by: created_at | tanggal | nama | status |
| order | string | DESC | Sort order: ASC | DESC |

**Response (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 67,
      "no_pengaduan": "ADU-202608-001",
      "tanggal": "2026-08-20",
      "waktu": "15:30",
      "nama": "Test User",
      "email": "test@email.com",
      "nohp": "08123456789",
      "jenis_responden": "peserta",
      "aduan": "Isi pengaduan...",
      "status": "waiting",
      "dibaca": false,
      "dibaca_oleh": null,
      "dibaca_tanggal": null,
      "riwayat_respon": []
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total": 50,
    "last_page": 3,
    "from": 1,
    "to": 20
  },
  "counts": {
    "all": 50,
    "waiting": 10,
    "processing": 15,
    "completed": 20,
    "archived": 5
  }
}
```

### 3. Get Counts by Status

**Endpoint:** `GET /admin/pengaduan/counts`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "all": 50,
    "waiting": 10,
    "processing": 15,
    "completed": 20,
    "archived": 5
  }
}
```

### 4. Detail Pengaduan

**Endpoint:** `GET /admin/pengaduan/{id}`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "id": 67,
    "no_pengaduan": "ADU-202608-001",
    "tanggal": "2026-08-20",
    "waktu": "15:30",
    "nama": "Test User",
    "email": "test@email.com",
    "nohp": "08123456789",
    "jenis_responden": "peserta",
    "aduan": "Isi lengkap pengaduan...",
    "status": "waiting",
    "dibaca": true,
    "dibaca_oleh": "admin",
    "dibaca_tanggal": "2026-08-20 16:00:00",
    "riwayat_respon": [
      {
        "id": 1,
        "pengaduan_id": 67,
        "tanggal": "2026-08-20",
        "waktu": "16:00",
        "admin": "Administrator",
        "isi": "Respon dari admin..."
      }
    ]
  }
}
```

### 5. Respon Pengaduan

**Endpoint:** `POST /admin/pengaduan/{id}/respon`

**Request Body:**
```json
{
  "tanggapan": "string (required, min 5 karakter)",
  "catatan_internal": "string (optional, max 1000)",
  "status": "string (required) - pilih: waiting | processing | completed | archived",
  "kirim_notifikasi": "boolean (optional)"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Respon berhasil dikirim",
  "data": {
    "id": 67,
    "no_pengaduan": "ADU-202608-001",
    "status": "processing",
    "respon_admin": "Respon dari admin...",
    "riwayat_respon": [...]
  }
}
```

### 6. Update Status

**Endpoint:** `PUT /admin/pengaduan/{id}/status`

**Request Body:**
```json
{
  "status": "string (required) - pilih: waiting | processing | completed | archived",
  "catatan": "string (optional, max 500)"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Status berhasil diupdate",
  "data": {
    "id": 67,
    "no_pengaduan": "ADU-202608-001",
    "status_lama": "waiting",
    "status_baru": "processing"
  }
}
```

### 7. Delete/Archive Pengaduan

**Endpoint:** `DELETE /admin/pengaduan/{id}?type=soft|hard`

**Query Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| type | string | soft | soft (arsipkan) | hard (hapus permanen) |

**Response (200):**
```json
{
  "success": true,
  "message": "Pengaduan berhasil diarsipkan"
}
```

---

## Status Flow

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

## Ringkasan Endpoint

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/pengaduan` | Public | Submit pengaduan baru |
| GET | `/admin/pengaduan` | Admin | List dengan filter & pagination |
| GET | `/admin/pengaduan/counts` | Admin | Get counts by status |
| GET | `/admin/pengaduan/{id}` | Admin | Detail pengaduan (auto mark as read) |
| POST | `/admin/pengaduan/{id}/respon` | Admin | Kirim respon |
| PUT | `/admin/pengaduan/{id}/status` | Admin | Update status |
| DELETE | `/admin/pengaduan/{id}` | Admin | Hapus/arsip |
