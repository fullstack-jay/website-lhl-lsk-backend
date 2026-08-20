<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LHK Sertifikasi - API Documentation</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5.10.0/swagger-ui.css">
    <style>
        html {
            box-sizing: border-box;
            overflow: -moz-scrollbars-vertical;
            overflow-y: scroll;
        }
        *, *:before, *:after {
            box-sizing: inherit;
        }
        body {
            margin: 0;
            padding: 0;
        }
        .topbar {
            background-color: #1f2937;
            padding: 15px 0;
            border-bottom: 1px solid #374151;
        }
        .topbar .wrapper {
            max-width: 1460px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar .link {
            display: inline-block;
            color: white;
            text-decoration: none;
            font-size: 24px;
            font-weight: bold;
        }
        .topbar .info {
            color: #9ca3af;
            font-size: 14px;
        }
        .topbar .nav-links {
            display: flex;
            gap: 20px;
        }
        .topbar .nav-link {
            color: #d1d5db;
            text-decoration: none;
            transition: color 0.2s;
        }
        .topbar .nav-link:hover {
            color: white;
        }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="wrapper">
            <div>
                <a class="link" href="{{ url('/') }}">LHK Sertifikasi API</a>
                <span class="info">v1.0.0</span>
            </div>
            <div class="nav-links">
                <a href="{{ url('/docs/swagger-pendaftaran.yaml') }}" class="nav-link" target="_blank">View YAML</a>
                <a href="{{ url('/docs/SWAGGER_PENDAFTARAN.md') }}" class="nav-link" target="_blank">View Docs</a>
                <a href="{{ url('/admin') }}" class="nav-link">Admin Panel</a>
            </div>
        </div>
    </div>

    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@5.10.0/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.10.0/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            const spec = {
                "openapi": "3.0.0",
                "info": {
                    "title": "LHK Sertifikasi - Pendaftaran API",
                    "description": "API documentation untuk sistem pendaftaran sertifikasi LHK. Password yang digenerate adalah 6 digit angka acak. Semua timestamps dalam format: d M Y H:i (Contoh: 20 Aug 2024 13:21)",
                    "version": "1.0.0",
                    "contact": {
                        "name": "API Support",
                        "email": "support@lhk-sertifikasi.com"
                    },
                    "license": {
                        "name": "MIT",
                        "url": "https://opensource.org/licenses/MIT"
                    }
                },
                "servers": [
                    {
                        "url": "{{ url('/api/v1') }}",
                        "description": "Local Development Server"
                    }
                ],
                "tags": [
                    {"name": "Pendaftaran", "description": "Operasi pendaftaran sertifikasi"},
                    {"name": "Authentication", "description": "Operasi autentikasi peserta (login, logout, user info)"},
                    {"name": "Public", "description": "Endpoint publik tanpa autentikasi"},
                    {"name": "Admin", "description": "Endpoint admin dengan autentikasi"}
                ],
                "paths": {
                    "/auth/login": {
                        "post": {
                            "tags": ["Authentication", "Public"],
                            "summary": "Login peserta",
                            "description": "Login menggunakan No. KTP/NIK atau No. Handphone beserta password yang didapat saat pendaftaran.",
                            "operationId": "loginPeserta",
                            "requestBody": {
                                "required": true,
                                "content": {
                                    "application/json": {
                                        "schema": {
                                            "type": "object",
                                            "required": ["identifier", "password"],
                                            "properties": {
                                                "identifier": {
                                                    "type": "string",
                                                    "example": "1234567890123456",
                                                    "description": "No. KTP/NIK atau No. Handphone (16 digit untuk KTP, 10-14 digit untuk HP)"
                                                },
                                                "password": {
                                                    "type": "string",
                                                    "minLength": 4,
                                                    "example": "123456",
                                                    "description": "Password yang didapat saat pendaftaran (6 digit angka)"
                                                }
                                            }
                                        },
                                        "example": {
                                            "identifier": "1234567890123456",
                                            "password": "123456"
                                        }
                                    }
                                }
                            },
                            "responses": {
                                "200": {
                                    "description": "Login berhasil",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean", "example": true},
                                                    "message": {"type": "string", "example": "Login berhasil"},
                                                    "data": {
                                                        "type": "object",
                                                        "properties": {
                                                            "user": {
                                                                "type": "object",
                                                                "properties": {
                                                                    "username": {"type": "string", "example": "1234567890123456"},
                                                                    "nama_lengkap": {"type": "string", "example": "Rizqi Reza Ardiansyah"},
                                                                    "email": {"type": "string", "format": "email", "example": "rizqi@example.com"},
                                                                    "no_hp": {"type": "string", "example": "081234567890"},
                                                                    "no_ktp": {"type": "string", "example": "1234567890123456"},
                                                                    "role": {"type": "string", "example": "USER"},
                                                                    "status": {"type": "string", "example": "ACTIVE"}
                                                                }
                                                            },
                                                            "token": {"type": "string", "description": "Bearer token untuk authenticated requests"},
                                                            "token_type": {"type": "string", "example": "Bearer"}
                                                        }
                                                    }
                                                }
                                            },
                                            "example": {
                                                "success": true,
                                                "message": "Login berhasil",
                                                "data": {
                                                    "user": {
                                                        "username": "1234567890123456",
                                                        "nama_lengkap": "Rizqi Reza Ardiansyah",
                                                        "email": "rizqi@example.com",
                                                        "no_hp": "081234567890",
                                                        "no_ktp": "1234567890123456",
                                                        "role": "USER",
                                                        "status": "ACTIVE"
                                                    },
                                                    "token": "1|aBcDeFgHiJkLmNoPqRsTuVwXyZ",
                                                    "token_type": "Bearer"
                                                }
                                            }
                                        }
                                    }
                                },
                                "401": {
                                    "description": "Login gagal - credentials salah atau user tidak ditemukan",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean", "example": false},
                                                    "message": {"type": "string"}
                                                }
                                            },
                                            "examples": {
                                                "user_not_found": {
                                                    "summary": "User tidak ditemukan",
                                                    "value": {
                                                        "success": false,
                                                        "message": "No. KTP/NIK atau No. Handphone tidak ditemukan"
                                                    }
                                                },
                                                "wrong_password": {
                                                    "summary": "Password salah",
                                                    "value": {
                                                        "success": false,
                                                        "message": "Password salah"
                                                    }
                                                }
                                            }
                                        }
                                    }
                                },
                                "422": {
                                    "description": "Validasi gagal"
                                }
                            }
                        }
                    },
                    "/auth/logout": {
                        "post": {
                            "tags": ["Authentication"],
                            "summary": "Logout peserta",
                            "description": "Logout dari sesi saat ini. Token yang digunakan akan di-revoke dan tidak bisa digunakan lagi.",
                            "operationId": "logoutPeserta",
                            "security": [{"bearerAuth": []}],
                            "responses": {
                                "200": {
                                    "description": "Logout berhasil",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean", "example": true},
                                                    "message": {"type": "string", "example": "Logout berhasil"}
                                                }
                                            },
                                            "example": {
                                                "success": true,
                                                "message": "Logout berhasil"
                                            }
                                        }
                                    }
                                },
                                "401": {
                                    "description": "Unauthorized - Token tidak valid atau expired"
                                }
                            }
                        }
                    },
                    "/auth/me": {
                        "get": {
                            "tags": ["Authentication"],
                            "summary": "Get user info saat ini",
                            "description": "Mengambil informasi user yang sedang login. Memerlukan Bearer token authentication.",
                            "operationId": "getUserInfo",
                            "security": [{"bearerAuth": []}],
                            "responses": {
                                "200": {
                                    "description": "Success",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean", "example": true},
                                                    "data": {
                                                        "type": "object",
                                                        "properties": {
                                                            "username": {"type": "string", "example": "1234567890123456"},
                                                            "nama_lengkap": {"type": "string", "example": "Rizqi Reza Ardiansyah"},
                                                            "email": {"type": "string", "format": "email", "example": "rizqi@example.com"},
                                                            "no_hp": {"type": "string", "example": "081234567890"},
                                                            "no_ktp": {"type": "string", "example": "1234567890123456"},
                                                            "role": {"type": "string", "example": "USER"},
                                                            "status": {"type": "string", "example": "ACTIVE"},
                                                            "created_at": {"type": "string", "example": "20 Aug 2024 13:21"}
                                                        }
                                                    }
                                                }
                                            },
                                            "example": {
                                                "success": true,
                                                "data": {
                                                    "username": "1234567890123456",
                                                    "nama_lengkap": "Rizqi Reza Ardiansyah",
                                                    "email": "rizqi@example.com",
                                                    "no_hp": "081234567890",
                                                    "no_ktp": "1234567890123456",
                                                    "role": "USER",
                                                    "status": "ACTIVE",
                                                    "created_at": "20 Aug 2024 13:21"
                                                }
                                            }
                                        }
                                    }
                                },
                                "401": {
                                    "description": "Unauthorized - Token tidak valid"
                                }
                            }
                        }
                    },
                    "/auth/update-password": {
                        "post": {
                            "tags": ["Authentication"],
                            "summary": "Update password peserta",
                            "description": "Update password user yang sedang login. Memerlukan Bearer token authentication.",
                            "operationId": "updatePassword",
                            "security": [{"bearerAuth": []}],
                            "requestBody": {
                                "required": true,
                                "content": {
                                    "application/json": {
                                        "schema": {
                                            "type": "object",
                                            "required": ["current_password", "password"],
                                            "properties": {
                                                "current_password": {
                                                    "type": "string",
                                                    "minLength": 4,
                                                    "description": "Password saat ini"
                                                },
                                                "password": {
                                                    "type": "string",
                                                    "minLength": 4,
                                                    "description": "Password baru"
                                                }
                                            }
                                        },
                                        "example": {
                                            "current_password": "123456",
                                            "password": "654321"
                                        }
                                    }
                                }
                            },
                            "responses": {
                                "200": {
                                    "description": "Password berhasil diperbarui",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean", "example": true},
                                                    "message": {"type": "string", "example": "Password berhasil diperbarui"}
                                                }
                                            },
                                            "example": {
                                                "success": true,
                                                "message": "Password berhasil diperbarui"
                                            }
                                        }
                                    }
                                },
                                "401": {
                                    "description": "Password saat ini salah atau token tidak valid"
                                },
                                "422": {
                                    "description": "Validasi gagal"
                                }
                            }
                        }
                    },
                    "/pendaftaran": {
                        "post": {
                            "tags": ["Pendaftaran", "Public"],
                            "summary": "Submit pendaftaran baru",
                            "description": "Submit form pendaftaran sertifikasi baru. Sistem akan otomatis generate nomor pendaftaran (REG-YYYYMMDD-XXXX) dan password 6 digit angka acak. Password langsung ditampilkan di response tanpa perlu verifikasi email.",
                            "operationId": "createPendaftaran",
                            "requestBody": {
                                "required": true,
                                "content": {
                                    "application/json": {
                                        "schema": {
                                            "type": "object",
                                            "required": ["nama", "email", "no_hp", "no_ktp", "kebangsaan", "kualifikasi_pendidikan", "bidang_keahlian", "alamat", "wil_ujikom", "nama_institusi", "jabatan", "alamat_kantor", "kode_pos"],
                                            "properties": {
                                                "nama": {"type": "string", "maxLength": 255, "example": "Budi Santoso", "description": "Nama lengkap peserta"},
                                                "email": {"type": "string", "format": "email", "maxLength": 255, "example": "budi@example.com", "description": "Email aktif untuk notifikasi (harus unik)"},
                                                "no_hp": {"type": "string", "maxLength": 14, "pattern": "^[0-9]+$", "example": "081234567890", "description": "Nomor handphone aktif"},
                                                "no_ktp": {"type": "string", "maxLength": 16, "minLength": 16, "pattern": "^[0-9]+$", "example": "1234567890123456", "description": "Nomor KTP 16 digit (harus unik)"},
                                                "kebangsaan": {"type": "string", "maxLength": 100, "example": "Indonesia", "description": "Kewarganegaraan"},
                                                "kualifikasi_pendidikan": {"type": "string", "enum": ["D4", "S1", "S2", "S3"], "example": "S1", "description": "Kualifikasi pendidikan terakhir"},
                                                "bidang_keahlian": {"type": "string", "maxLength": 255, "example": "Teknik Lingkungan", "description": "Bidang keahlian/profesi"},
                                                "alamat": {"type": "string", "example": "Jl. Merdeka No. 1, Jakarta Pusat", "description": "Alamat lengkap domisili"},
                                                "propinsi": {"type": "string", "maxLength": 100, "example": "DKI Jakarta", "description": "Provinsi (opsional)"},
                                                "kota": {"type": "string", "maxLength": 100, "example": "Jakarta Pusat", "description": "Kota/Kabupaten (opsional)"},
                                                "kecamatan": {"type": "string", "maxLength": 100, "example": "Gambir", "description": "Kecamatan (opsional)"},
                                                "kelurahan": {"type": "string", "maxLength": 100, "example": "Gambir", "description": "Kelurahan (opsional)"},
                                                "wil_ujikom": {"type": "string", "maxLength": 100, "example": "Jakarta", "description": "Lokasi uji kompetensi yang dipilih"},
                                                "nama_institusi": {"type": "string", "maxLength": 255, "example": "PT. Lingkungan Bersih Jaya", "description": "Nama institusi/perusahaan tempat bekerja"},
                                                "jabatan": {"type": "string", "maxLength": 255, "example": "Environmental Engineer", "description": "Jabatan saat ini"},
                                                "alamat_kantor": {"type": "string", "example": "Jl. Sudirman No. 123, Jakarta Selatan", "description": "Alamat lengkap kantor"},
                                                "kode_pos": {"type": "string", "maxLength": 5, "pattern": "^[0-9]+$", "example": "10220", "description": "Kode pos kantor"},
                                                "no_telp_kantor": {"type": "string", "maxLength": 20, "example": "02112345678", "description": "Nomor telepon kantor (opsional)"},
                                                "no_fax_kantor": {"type": "string", "maxLength": 20, "example": "02112345679", "description": "Nomor fax kantor (opsional)"},
                                                "email_kantor": {"type": "string", "format": "email", "maxLength": 255, "example": "info@lingkungan.com", "description": "Email kantor (opsional)"}
                                            }
                                        },
                                        "example": {
                                            "nama": "Budi Santoso",
                                            "email": "budi@example.com",
                                            "no_hp": "081234567890",
                                            "no_ktp": "1234567890123456",
                                            "kebangsaan": "Indonesia",
                                            "kualifikasi_pendidikan": "S1",
                                            "bidang_keahlian": "Teknik Lingkungan",
                                            "alamat": "Jl. Merdeka No. 1, Jakarta Pusat",
                                            "propinsi": "DKI Jakarta",
                                            "kota": "Jakarta Pusat",
                                            "kecamatan": "Gambir",
                                            "kelurahan": "Gambir",
                                            "wil_ujikom": "Jakarta",
                                            "nama_institusi": "PT. Lingkungan Bersih Jaya",
                                            "jabatan": "Environmental Engineer",
                                            "alamat_kantor": "Jl. Sudirman No. 123, Jakarta Selatan",
                                            "kode_pos": "10220",
                                            "no_telp_kantor": "02112345678",
                                            "no_fax_kantor": "02112345679",
                                            "email_kantor": "info@lingkungan.com"
                                        }
                                    }
                                }
                            },
                            "responses": {
                                "201": {
                                    "description": "Pendaftaran berhasil dibuat",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean", "example": true},
                                                    "message": {"type": "string", "example": "Pendaftaran berhasil! Data Anda telah tersimpan dengan nomor pendaftaran dan password di bawah."},
                                                    "data": {
                                                        "type": "object",
                                                        "properties": {
                                                            "no_pendaftaran": {"type": "string", "example": "REG-20240820-0001", "description": "Nomor pendaftaran yang digenerate"},
                                                            "nama": {"type": "string", "example": "Budi Santoso", "description": "Nama lengkap peserta"},
                                                            "no_hp": {"type": "string", "example": "081234567890", "description": "Nomor handphone peserta"},
                                                            "email": {"type": "string", "format": "email", "example": "budi@example.com", "description": "Email peserta"},
                                                            "password": {"type": "string", "example": "123456", "description": "Password yang digenerate (6 digit angka)"},
                                                            "status": {"type": "string", "enum": ["PENDING", "DIVERIFIKASI", "DISETUJUI", "DITOLAK"], "example": "DIVERIFIKASI"},
                                                            "login_info": {
                                                                "type": "object",
                                                                "description": "Informasi untuk login",
                                                                "properties": {
                                                                    "username": {"type": "string", "example": "1234567890123456", "description": "Username (no_ktp) untuk login"},
                                                                    "no_hp_login": {"type": "string", "example": "081234567890", "description": "No HP juga bisa digunakan untuk login"},
                                                                    "role": {"type": "string", "example": "USER", "description": "Role otomatis PESERTA"}
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            },
                                            "example": {
                                                "success": true,
                                                "message": "Pendaftaran berhasil! Data Anda telah tersimpan dengan nomor pendaftaran dan password di bawah.",
                                                "data": {
                                                    "no_pendaftaran": "REG-20240820-0001",
                                                    "nama": "Budi Santoso",
                                                    "no_hp": "081234567890",
                                                    "email": "budi@example.com",
                                                    "password": "123456",
                                                    "status": "DIVERIFIKASI",
                                                    "login_info": {
                                                        "username": "1234567890123456",
                                                        "no_hp_login": "081234567890",
                                                        "role": "USER"
                                                    }
                                                }
                                            }
                                        }
                                    }
                                },
                                "422": {
                                    "description": "Validasi gagal",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean", "example": false},
                                                    "message": {"type": "string", "example": "Validasi gagal"},
                                                    "errors": {"type": "object"}
                                                }
                                            },
                                            "example": {
                                                "success": false,
                                                "message": "Validasi gagal",
                                                "errors": {
                                                    "email": ["Email sudah terdaftar"],
                                                    "no_ktp": ["Nomor KTP harus 16 karakter"]
                                                }
                                            }
                                        }
                                    }
                                },
                                "500": {
                                    "description": "Internal server error"
                                }
                            }
                        },
                        "get": {
                            "tags": ["Pendaftaran", "Admin"],
                            "summary": "Get semua pendaftaran",
                            "description": "Mengambil list semua pendaftaran dengan filter dan pagination. Memerlukan Bearer token authentication.",
                            "operationId": "getPendaftarans",
                            "security": [{"bearerAuth": []}],
                            "parameters": [
                                {
                                    "name": "status",
                                    "in": "query",
                                    "description": "Filter berdasarkan status",
                                    "schema": {"type": "string", "enum": ["PENDING", "DIVERIFIKASI", "DISETUJUI", "DITOLAK"]},
                                    "example": "PENDING"
                                },
                                {
                                    "name": "search",
                                    "in": "query",
                                    "description": "Search by nama, no_pendaftaran, atau email",
                                    "schema": {"type": "string"},
                                    "example": "Budi"
                                },
                                {
                                    "name": "per_page",
                                    "in": "query",
                                    "description": "Jumlah item per halaman (default: 15)",
                                    "schema": {"type": "integer", "default": 15, "minimum": 1, "maximum": 100},
                                    "example": 15
                                },
                                {
                                    "name": "page",
                                    "in": "query",
                                    "description": "Nomor halaman (default: 1)",
                                    "schema": {"type": "integer", "default": 1, "minimum": 1},
                                    "example": 1
                                }
                            ],
                            "responses": {
                                "200": {
                                    "description": "Success",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean"},
                                                    "data": {"type": "array", "items": {"type": "object"}},
                                                    "pagination": {
                                                        "type": "object",
                                                        "properties": {
                                                            "total": {"type": "integer", "description": "Total semua data"},
                                                            "per_page": {"type": "integer", "description": "Jumlah per halaman"},
                                                            "current_page": {"type": "integer", "description": "Halaman saat ini"},
                                                            "last_page": {"type": "integer", "description": "Total halaman"}
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                },
                                "401": {"description": "Unauthorized - Bearer token required"}
                            }
                        },
                        "delete": {
                            "tags": ["Pendaftaran", "Admin"],
                            "summary": "Hapus pendaftaran",
                            "description": "Soft delete data pendaftaran. Data tidak dihapus permanen dari database.",
                            "operationId": "deletePendaftaran",
                            "security": [{"bearerAuth": []}],
                            "parameters": [
                                {"name": "id", "in": "path", "required": true, "description": "ID pendaftaran", "schema": {"type": "integer"}, "example": 1}
                            ],
                            "responses": {
                                "200": {
                                    "description": "Success",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean"},
                                                    "message": {"type": "string"}
                                                }
                                            },
                                            "example": {
                                                "success": true,
                                                "message": "Data pendaftaran berhasil dihapus"
                                            }
                                        }
                                    }
                                },
                                "404": {"description": "Data tidak ditemukan"}
                            }
                        }
                    },
                    "/pendaftaran/statistics": {
                        "get": {
                            "tags": ["Pendaftaran", "Admin"],
                            "summary": "Get statistik pendaftaran",
                            "description": "Mengambil data statistik pendaftaran berdasarkan status. Memerlukan Bearer token authentication.",
                            "operationId": "getPendaftaranStatistics",
                            "security": [{"bearerAuth": []}],
                            "responses": {
                                "200": {
                                    "description": "Success",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean"},
                                                    "data": {
                                                        "type": "object",
                                                        "properties": {
                                                            "total": {"type": "integer", "description": "Total semua pendaftaran"},
                                                            "pending": {"type": "integer", "description": "Jumlah pendaftaran pending"},
                                                            "diverifikasi": {"type": "integer", "description": "Jumlah pendaftaran diverifikasi"},
                                                            "disetujui": {"type": "integer", "description": "Jumlah pendaftaran disetujui"},
                                                            "ditolak": {"type": "integer", "description": "Jumlah pendaftaran ditolak"}
                                                        }
                                                    }
                                                }
                                            },
                                            "example": {
                                                "success": true,
                                                "data": {
                                                    "total": 100,
                                                    "pending": 25,
                                                    "diverifikasi": 15,
                                                    "disetujui": 50,
                                                    "ditolak": 10
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    },
                    "/pendaftaran/{no_pendaftaran}": {
                        "get": {
                            "tags": ["Pendaftaran", "Public"],
                            "summary": "Cek status pendaftaran",
                            "description": "Mengambil detail pendaftaran berdasarkan nomor pendaftaran tanpa memerlukan authentication.",
                            "operationId": "getPendaftaranByNo",
                            "parameters": [
                                {
                                    "name": "no_pendaftaran",
                                    "in": "path",
                                    "required": true,
                                    "description": "Nomor pendaftaran (format: REG-YYYYMMDD-XXXX)",
                                    "schema": {"type": "string"},
                                    "example": "REG-20240820-0001"
                                }
                            ],
                            "responses": {
                                "200": {
                                    "description": "Success",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean"},
                                                    "data": {
                                                        "type": "object",
                                                        "properties": {
                                                            "id": {"type": "integer"},
                                                            "no_pendaftaran": {"type": "string"},
                                                            "nama": {"type": "string"},
                                                            "email": {"type": "string"},
                                                            "no_hp": {"type": "string"},
                                                            "kebangsaan": {"type": "string"},
                                                            "kualifikasi_pendidikan": {"type": "string"},
                                                            "bidang_keahlian": {"type": "string"},
                                                            "wil_ujikom": {"type": "string"},
                                                            "nama_institusi": {"type": "string"},
                                                            "jabatan": {"type": "string"},
                                                            "status": {"type": "string"},
                                                            "catatan": {"type": "string"},
                                                            "tanggal_verifikasi": {"type": "string"},
                                                            "created_at": {"type": "string"}
                                                        }
                                                    }
                                                }
                                            },
                                            "example": {
                                                "success": true,
                                                "data": {
                                                    "id": 1,
                                                    "no_pendaftaran": "REG-20240820-0001",
                                                    "nama": "Budi Santoso",
                                                    "email": "budi@example.com",
                                                    "no_hp": "081234567890",
                                                    "kebangsaan": "Indonesia",
                                                    "kualifikasi_pendidikan": "S1",
                                                    "bidang_keahlian": "Teknik Lingkungan",
                                                    "wil_ujikom": "Jakarta",
                                                    "nama_institusi": "PT. Lingkungan Bersih Jaya",
                                                    "jabatan": "Environmental Engineer",
                                                    "status": "DIVERIFIKASI",
                                                    "catatan": null,
                                                    "tanggal_verifikasi": null,
                                                    "created_at": "20 Aug 2024 13:21"
                                                }
                                            }
                                        }
                                    }
                                },
                                "404": {
                                    "description": "Data tidak ditemukan",
                                    "content": {
                                        "application/json": {
                                            "example": {
                                                "success": false,
                                                "message": "Data pendaftaran tidak ditemukan"
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    },
                    "/pendaftaran/{id}/status": {
                        "put": {
                            "tags": ["Pendaftaran", "Admin"],
                            "summary": "Update status pendaftaran",
                            "description": "Update status verifikasi pendaftaran. Memerlukan Bearer token authentication.",
                            "operationId": "updatePendaftaranStatus",
                            "security": [{"bearerAuth": []}],
                            "parameters": [
                                {"name": "id", "in": "path", "required": true, "description": "ID pendaftaran", "schema": {"type": "integer"}, "example": 1}
                            ],
                            "requestBody": {
                                "required": true,
                                "content": {
                                    "application/json": {
                                        "schema": {
                                            "type": "object",
                                            "required": ["status"],
                                            "properties": {
                                                "status": {
                                                    "type": "string",
                                                    "enum": ["DIVERIFIKASI", "DISETUJUI", "DITOLAK"],
                                                    "description": "Status baru pendaftaran"
                                                },
                                                "catatan": {
                                                    "type": "string",
                                                    "description": "Catatan verifikasi (opsional)"
                                                }
                                            }
                                        },
                                        "example": {
                                            "status": "DISETUJUI",
                                            "catatan": "Dokumen lengkap dan valid"
                                        }
                                    }
                                }
                            },
                            "responses": {
                                "200": {
                                    "description": "Success",
                                    "content": {
                                        "application/json": {
                                            "schema": {
                                                "type": "object",
                                                "properties": {
                                                    "success": {"type": "boolean"},
                                                    "message": {"type": "string"},
                                                    "data": {"type": "object"}
                                                }
                                            },
                                            "example": {
                                                "success": true,
                                                "message": "Status pendaftaran berhasil diperbarui",
                                                "data": {
                                                    "id": 1,
                                                    "no_pendaftaran": "REG-20240820-0001",
                                                    "status": "DISETUJUI",
                                                    "catatan": "Dokumen lengkap dan valid",
                                                    "tanggal_verifikasi": "20 Aug 2024 15:30"
                                                }
                                            }
                                        }
                                    }
                                },
                                "422": {
                                    "description": "Validasi gagal"
                                },
                                "404": {
                                    "description": "Data tidak ditemukan"
                                }
                            }
                        }
                    }
                },
                "components": {
                    "securitySchemes": {
                        "bearerAuth": {
                            "type": "http",
                            "scheme": "bearer",
                            "bearerFormat": "JWT",
                            "description": "Authentication token menggunakan Laravel Sanctum. Untuk mendapatkan token, login terlebih dahulu dan gunakan token yang diberikan."
                        }
                    }
                }
            };

            SwaggerUIBundle({
                spec: spec,
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "StandaloneLayout",
                defaultModelsExpandDepth: 1,
                defaultModelExpandDepth: 1,
                docExpansion: "list",
                filter: true,
                tryItOutEnabled: true,
                persistAuthorization: true,
                withCredentials: true,
                validatorUrl: null,
                displayRequestDuration: true,
                displayOperationId: false,
                syntaxHighlight: {
                    activate: true,
                    theme: "monokai"
                }
            });
        };
    </script>
</body>
</html>