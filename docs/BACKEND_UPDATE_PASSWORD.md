# Backend Documentation - Update Password (Laravel)

## Overview
Dokumentasi ini menjelaskan implementasi backend Laravel untuk fitur update password langsung tanpa memerlukan kata sandi lama. Fitur ini digunakan pada halaman "Ubah Kata Sandi" di frontend.

---

## 1. Update Password Controller

```php
// app/Http/Controllers/Auth/UpdatePasswordController.php

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdatePasswordController extends Controller
{
    /**
     * Handle update password request
     * POST /api/v1/auth/update-password
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        // Get authenticated user from token
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'password' => 'required|min:6|confirmed',
        ], [
            'password.required' => 'Kata sandi baru wajib diisi',
            'password.min' => 'Kata sandi minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Revoke all tokens for security (force user to login again)
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kata sandi berhasil diubah',
        ], 200);
    }
}
```

---

## 2. Routes

Tambahkan route di file `routes/api.php`:

```php
// routes/api.php

use App\Http\Controllers\Auth\UpdatePasswordController;

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Update password
    Route::post('/auth/update-password', [UpdatePasswordController::class, 'update'])
        ->name('password.update');
});
```

---

## 3. API Endpoint

### Update Password
```
POST /api/v1/auth/update-password
Authorization: Bearer {token}
Content-Type: application/json

Request Body:
{
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}

Success Response (200):
{
  "success": true,
  "message": "Kata sandi berhasil diubah"
}

Error Response (401):
{
  "success": false,
  "message": "Sesi tidak valid. Silakan login kembali."
}

Error Response (422):
{
  "success": false,
  "message": "Validasi gagal",
  "errors": {
    "password": ["Kata sandi baru wajib diisi"]
  }
}
```

---

## 4. Frontend Implementation

### API Service Update
```typescript
// src/services/authApi.ts

updatePassword: async (
  token: string,
  data: { password: string; password_confirmation: string }
): Promise<{ success: boolean; message: string }> => {
  const response = await fetch(`${import.meta.env.VITE_API_URL}/api/${import.meta.env.VITE_API_VERSION}/auth/update-password`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify(data),
  });

  if (!response.ok) {
    throw new Error('Failed to update password');
  }

  return response.json();
},
```

---

## 5. Flow Diagram

```
1. User logged in mengakses halaman "Ubah Kata Sandi"
   ↓
2. User memasukkan kata sandi baru dan konfirmasi
   ↓
3. Frontend calls: POST /api/v1/auth/update-password with token
   ↓
4. Backend validates request and updates password
   ↓
5. Backend revokes all tokens (security measure)
   ↓
6. Frontend shows success message
   ↓
7. Frontend logs out user and redirects to login page
   ↓
8. User logs in with new password
```

---

## 6. Security Considerations

1. **Authentication Required**: Endpoint ini dilindungi dengan middleware `auth:sanctum`

2. **Token Revocation**: Setelah password berhasil diubah, semua token user di-revoke untuk memaksa login ulang

3. **Password Confirmation**: Menggunakan validasi `confirmed` untuk memastikan user mengetik password dengan benar

4. **Minimum Length**: Password minimal 6 karakter sesuai kebijakan sistem

---

## 7. Testing dengan cURL

```bash
# First, login to get token
curl -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "identifier": "peserta@example.com",
    "password": "oldpassword"
  }'

# Save the token from response

# Then update password
curl -X POST http://127.0.0.1:8000/api/v1/auth/update-password \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -d '{
    "password": "newpassword123",
    "password_confirmation": "newpassword123"
  }'

# Expected response:
# {
#   "success": true,
#   "message": "Kata sandi berhasil diubah"
# }

# Try to use old token - should fail (401)
```

---

## 8. Notes

1. **No Old Password Required**: Endpoint ini tidak memerlukan password lama karena user sudah terautentikasi dengan token

2. **Auto Logout**: Setelah update berhasil, user harus login kembali karena token di-revoke

3. **Rate Limiting**: Disarankan menambahkan rate limiting untuk mencegah brute force attack

4. **Audit Log**: Untuk production, disarankan mencatat log aktivitas perubahan password