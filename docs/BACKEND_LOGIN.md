# BACKEND LOGIN - Laravel Authentication System

## Overview
Dokumentasi ini menjelaskan implementasi backend Laravel untuk sistem autentikasi login yang akan digunakan di frontend aplikasi LHK sertifikasi.

---

## 1. DATABASE SCHEMA (MySQL)

### 1.1 Migration: Create Users Table

```php
// database/migrations/2024_08_20_000001_create_users_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('no_hp', 14)->nullable();
            $table->string('no_ktp', 16)->nullable();
            
            // Role system
            $table->enum('role', ['ADMIN', 'PESERTA', 'KOMITE_TEKNIS', 'EVALUATOR', 'PENGUJI'])->default('PESERTA');
            
            // Status user
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'SUSPENDED'])->default('ACTIVE');
            
            // Profile fields
            $table->string('avatar')->nullable();
            $table->text('alamat')->nullable();
            
            // Remember token
            $table->rememberToken();
            
            // Timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('email');
            $table->index('role');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

### 1.2 Migration: Create Password Reset Tokens Table

```php
// database/migrations/2024_08_20_000002_create_password_reset_tokens_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
```

### 1.3 Migration: Create Login Logs Table (Optional - untuk audit)

```php
// database/migrations/2024_08_20_000003_create_login_logs_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('login_at');
            $table->timestamp('logout_at')->nullable();
            $table->enum('status', ['SUCCESS', 'FAILED'])->default('SUCCESS');
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('login_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
    }
};
```

---

## 2. MODEL

### 2.1 User Model

```php
// app/Models/User.php

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'no_hp',
        'no_ktp',
        'role',
        'status',
        'avatar',
        'alamat',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Get user's full name attribute
     */
    public function getFullNameAttribute(): string
    {
        return $this->name;
    }

    /**
     * Get user's avatar URL
     */
    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return Storage::url($this->avatar);
        }
        return asset('images/default-avatar.png');
    }

    /**
     * Scope untuk active users
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    /**
     * Scope untuk role filtering
     */
    public function scopeWithRole($query, $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'ADMIN';
    }

    /**
     * Check if user is peserta
     */
    public function isPeserta(): bool
    {
        return $this->role === 'PESERTA';
    }

    /**
     * Check if user is komite teknis
     */
    public function isKomiteTeknis(): bool
    {
        return $this->role === 'KOMITE_TEKNIS';
    }

    /**
     * Relationship with login logs
     */
    public function loginLogs()
    {
        return $this->hasMany(LoginLog::class);
    }

    /**
     * Get the latest login log
     */
    public function latestLogin()
    {
        return $this->hasOne(LoginLog::class)->latestOfMany();
    }
}
```

### 2.2 LoginLog Model

```php
// app/Models/LoginLog.php

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'login_at',
        'logout_at',
        'status',
        'failure_reason',
    ];

    protected $casts = [
        'login_at' => 'datetime',
        'logout_at' => 'datetime',
    ];

    /**
     * Relationship with user
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

---

## 3. CONTROLLER

### 3.1 Auth Controller

```php
// app/Http/Controllers/api/AuthController.php

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\LoginLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login user
     * POST /api/v1/auth/login
     */
    public function login(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ], [
            'email.required' => 'Email wajib diisi',
            'email.email' => 'Format email tidak valid',
            'password.required' => 'Password wajib diisi',
            'password.min' => 'Password minimal 6 karakter',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Find user by email
        $user = User::where('email', $request->email)->first();

        // Check credentials
        if (!$user || !Hash::check($request->password, $user->password)) {
            // Log failed login attempt
            if ($user) {
                LoginLog::create([
                    'user_id' => $user->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'login_at' => now(),
                    'status' => 'FAILED',
                    'failure_reason' => 'Invalid credentials',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Email atau password salah',
            ], 401);
        }

        // Check if user is active
        if ($user->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda tidak aktif. Silakan hubungi administrator.',
            ], 403);
        }

        // Create token for API authentication
        $token = $user->createToken('auth-token')->plainTextToken;

        // Log successful login
        LoginLog::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'login_at' => now(),
            'status' => 'SUCCESS',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'avatar' => $user->avatar_url,
                    'no_hp' => $user->no_hp,
                    'alamat' => $user->alamat,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 200);
    }

    /**
     * Register new user (optional - for admin use)
     * POST /api/v1/auth/register
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'no_hp' => 'nullable|string|max:14',
            'no_ktp' => 'nullable|string|max:16',
            'role' => 'nullable|in:ADMIN,PESERTA,KOMITE_TEKNIS,EVALUATOR,PENGUJI',
            'alamat' => 'nullable|string',
        ], [
            'name.required' => 'Nama wajib diisi',
            'email.required' => 'Email wajib diisi',
            'email.unique' => 'Email sudah terdaftar',
            'password.required' => 'Password wajib diisi',
            'password.min' => 'Password minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi password tidak cocok',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'no_hp' => $request->no_hp,
                'no_ktp' => $request->no_ktp,
                'role' => $request->role ?? 'PESERTA',
                'alamat' => $request->alamat,
            ]);

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Registrasi berhasil',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat registrasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get authenticated user info
     * GET /api/v1/auth/me
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'avatar' => $user->avatar_url,
                'no_hp' => $user->no_hp,
                'no_ktp' => $user->no_ktp,
                'alamat' => $user->alamat,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
                'last_login' => $user->latestLogin?->login_at,
            ],
        ]);
    }

    /**
     * Logout user
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request)
    {
        try {
            // Update logout time in login log
            $latestLogin = LoginLog::where('user_id', $request->user()->id)
                ->where('status', 'SUCCESS')
                ->latest()
                ->first();

            if ($latestLogin && !$latestLogin->logout_at) {
                $latestLogin->update(['logout_at' => now()]);
            }

            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat logout',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh token
     * POST /api/v1/auth/refresh
     */
    public function refresh(Request $request)
    {
        try {
            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            // Create new token
            $token = $request->user()->createToken('auth-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token berhasil diperbarui',
                'data' => [
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui token',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update password
     * POST /api/v1/auth/update-password
     */
    public function updatePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'current_password.required' => 'Password saat ini wajib diisi',
            'password.required' => 'Password baru wajib diisi',
            'password.min' => 'Password minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi password tidak cocok',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        // Check current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password saat ini salah',
            ], 401);
        }

        try {
            $user->update([
                'password' => Hash::make($request->password),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password berhasil diperbarui',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui password',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Request password reset
     * POST /api/v1/auth/forgot-password
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Email tidak ditemukan',
            ], 404);
        }

        // Generate reset token
        $token = Str::random(60);

        // Store token
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        // Send email (implement email sending logic)
        // Mail::to($user)->send(new PasswordResetMail($token));

        return response()->json([
            'success' => true,
            'message' => 'Link reset password telah dikirim ke email Anda',
        ]);
    }

    /**
     * Reset password
     * POST /api/v1/auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify token
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
            return response()->json([
                'success' => false,
                'message' => 'Token reset password tidak valid',
            ], 400);
        }

        // Update password
        $user = User::where('email', $request->email)->first();
        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Delete token
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil direset',
        ]);
    }
}
```

---

## 4. ROUTES

```php
// routes/api.php

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

// Public routes
Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        // Login
        Route::post('login', [AuthController::class, 'login']);
        
        // Register (optional - usually admin only)
        Route::post('register', [AuthController::class, 'register']);
        
        // Forgot password
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        
        // Reset password
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });
});

// Protected routes (require authentication)
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        // Get current user info
        Route::get('me', [AuthController::class, 'me']);
        
        // Logout
        Route::post('logout', [AuthController::class, 'logout']);
        
        // Refresh token
        Route::post('refresh', [AuthController::class, 'refresh']);
        
        // Update password
        Route::post('update-password', [AuthController::class, 'updatePassword']);
    });
    
    // Role-based routes
    Route::middleware('role:admin')->group(function () {
        // Admin only routes
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
    });
});
```

---

## 5. MIDDLEWARE

### 5.1 Role Middleware

```php
// app/Http/Middleware/RoleMiddleware.php

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, $role)
    {
        if (!$request->user() || $request->user()->role !== strtoupper($role)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access',
            ], 403);
        }

        return $next($request);
    }
}
```

### 5.2 Register Middleware

```php
// app/Http/Kernel.php

protected $middlewareAliases = [
    // ... existing middleware
    'role' => \App\Http\Middleware\RoleMiddleware::class,
];
```

---

## 6. FRONTEND API INTEGRATION

### 6.1 Create Auth API Service

```typescript
// src/services/authApi.ts

import { api, apiRequest, ApiError } from '@/lib/api';

/**
 * Login Request Type
 */
export interface LoginRequest {
  email: string;
  password: string;
}

/**
 * Login Response Type
 */
export interface LoginResponse {
  success: boolean;
  message: string;
  data?: {
    user: {
      id: number;
      name: string;
      email: string;
      role: string;
      status: string;
      avatar: string | null;
      no_hp: string | null;
      alamat: string | null;
    };
    token: string;
    token_type: string;
  };
  errors?: Record<string, string[]>;
}

/**
 * User Info Response Type
 */
export interface UserInfoResponse {
  success: boolean;
  data: {
    id: number;
    name: string;
    email: string;
    role: string;
    status: string;
    avatar: string | null;
    no_hp: string | null;
    no_ktp: string | null;
    alamat: string | null;
    email_verified_at: string | null;
    created_at: string;
    last_login: string | null;
  };
}

/**
 * Auth API Service
 */
export const authApi = {
  /**
   * Login
   * POST /api/v1/auth/login
   */
  login: async (credentials: LoginRequest): Promise<LoginResponse> => {
    return apiRequest<LoginResponse>('/auth/login', {
      method: 'POST',
      body: JSON.stringify(credentials),
    });
  },

  /**
   * Get current user info
   * GET /api/v1/auth/me
   */
  me: async (token: string): Promise<UserInfoResponse> => {
    return api.get<UserInfoResponse>('/auth/me', {
      headers: {
        'Authorization': `Bearer ${token}`,
      },
    });
  },

  /**
   * Logout
   * POST /api/v1/auth/logout
   */
  logout: async (token: string): Promise<{ success: boolean; message: string }> => {
    return apiRequest<{ success: boolean; message: string }>('/auth/logout', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
      },
    });
  },

  /**
   * Refresh token
   * POST /api/v1/auth/refresh
   */
  refresh: async (token: string): Promise<{ success: boolean; message: string; data?: { token: string; token_type: string } }> => {
    return apiRequest('/auth/refresh', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
      },
    });
  },

  /**
   * Update password
   * POST /api/v1/auth/update-password
   */
  updatePassword: async (
    token: string,
    data: { current_password: string; password: string; password_confirmation: string }
  ): Promise<{ success: boolean; message: string }> => {
    return apiRequest('/auth/update-password', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify(data),
    });
  },

  /**
   * Forgot password
   * POST /api/v1/auth/forgot-password
   */
  forgotPassword: async (email: string): Promise<{ success: boolean; message: string }> => {
    return apiRequest('/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email }),
    });
  },

  /**
   * Reset password
   * POST /api/v1/auth/reset-password
   */
  resetPassword: async (
    data: { token: string; email: string; password: string; password_confirmation: string }
  ): Promise<{ success: boolean; message: string }> => {
    return apiRequest('/auth/reset-password', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },
};

/**
 * Helper to convert API errors to form errors
 */
export function convertAuthErrors(apiErrors?: Record<string, string[]>): Record<string, string> {
  if (!apiErrors) return {};

  const formErrors: Record<string, string> = {};
  Object.keys(apiErrors).forEach((key) => {
    formErrors[key] = apiErrors[key]?.[0] || 'Validation error';
  });

  return formErrors;
}
```

### 6.2 Auth Context/Hook (Optional)

```typescript
// src/contexts/AuthContext.tsx

import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { authApi, type LoginRequest, type UserInfoResponse } from '@/services/authApi';

interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  status: string;
  avatar: string | null;
  no_hp: string | null;
  alamat: string | null;
}

interface AuthContextType {
  user: User | null;
  token: string | null;
  login: (credentials: LoginRequest) => Promise<void>;
  logout: () => Promise<void>;
  isAuthenticated: boolean;
  isLoading: boolean;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider = ({ children }: { children: ReactNode }) => {
  const [user, setUser] = useState<User | null>(null);
  const [token, setToken] = useState<string | null>(
    localStorage.getItem('auth_token')
  );
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    if (token) {
      // Verify token and get user info
      authApi.me(token)
        .then((response) => {
          setUser(response.data);
        })
        .catch(() => {
          // Invalid token, clear it
          localStorage.removeItem('auth_token');
          setToken(null);
        });
    }
  }, [token]);

  const login = async (credentials: LoginRequest) => {
    setIsLoading(true);
    try {
      const response = await authApi.login(credentials);
      
      if (response.success && response.data) {
        setToken(response.data.token);
        setUser(response.data.user);
        localStorage.setItem('auth_token', response.data.token);
      } else {
        throw new Error(response.message || 'Login failed');
      }
    } finally {
      setIsLoading(false);
    }
  };

  const logout = async () => {
    if (token) {
      await authApi.logout(token);
    }
    setToken(null);
    setUser(null);
    localStorage.removeItem('auth_token');
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        login,
        logout,
        isAuthenticated: !!user,
        isLoading,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
};
```

---

## 7. TESTING DENGAN POSTMAN/THUNDER CLIENT

### POST /api/v1/auth/login

```json
{
  "email": "peserta@example.com",
  "password": "password123"
}
```

### Response Success (200)

```json
{
  "success": true,
  "message": "Login berhasil",
  "data": {
    "user": {
      "id": 1,
      "name": "Peserta Test",
      "email": "peserta@example.com",
      "role": "PESERTA",
      "status": "ACTIVE",
      "avatar": "http://localhost:8000/storage/avatars/default.png",
      "no_hp": "081234567890",
      "alamat": null
    },
    "token": "1|aBcDeFgHiJkLmNoPqRsTuVwXyZ",
    "token_type": "Bearer"
  }
}
```

### Response Error (401)

```json
{
  "success": false,
  "message": "Email atau password salah"
}
```

### GET /api/v1/auth/me (Protected)

Headers:
```
Authorization: Bearer 1|aBcDeFgHiJkLmNoPqRsTuVwXyZ
```

Response:
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Peserta Test",
    "email": "peserta@example.com",
    "role": "PESERTA",
    "status": "ACTIVE",
    "avatar": "http://localhost:8000/storage/avatars/default.png",
    "no_hp": "081234567890",
    "no_ktp": null,
    "alamat": null,
    "email_verified_at": "2024-08-20T10:00:00.000000Z",
    "created_at": "2024-08-20T08:00:00.000000Z",
    "last_login": "2024-08-20T12:30:00.000000Z"
  }
}
```

---

## 8. SEEDER (Untuk Testing)

```php
// database/seeders/UserSeeder.php

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        User::create([
            'name' => 'Administrator',
            'email' => 'admin@lhk.com',
            'password' => Hash::make('admin123'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'no_hp' => '081234567890',
        ]);

        // Create peserta user
        User::create([
            'name' => 'Peserta Test',
            'email' => 'peserta@lhk.com',
            'password' => Hash::make('peserta123'),
            'role' => 'PESERTA',
            'status' => 'ACTIVE',
            'no_hp' => '081234567891',
        ]);

        // Create komite teknis user
        User::create([
            'name' => 'Komite Teknis Test',
            'email' => 'komite@lhk.com',
            'password' => Hash::make('komite123'),
            'role' => 'KOMITE_TEKNIS',
            'status' => 'ACTIVE',
            'no_hp' => '081234567892',
        ]);
    }
}
```

---

## 9. SECURITY CONSIDERATIONS

### 9.1 Rate Limiting

```php
// app/Providers/RouteServiceProvider.php

protected function configureRateLimiting()
{
    // Rate limit login attempts
    RateLimiter::for('auth', function (Request $request) {
        return Limit::perMinute(5, 1)->by($request->ip());
    });
}
```

Apply in routes:
```php
Route::middleware('throttle:auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
});
```

### 9.2 Token Expiration

```php
// config/sanctum.php

'expiration' => 60 * 24, // 24 hours
```

### 9.3 CORS Configuration

```php
// config/cors.php

'paths' => ['api/*', 'sanctum/csrf-cookie'],

'allowed_methods' => ['*'],

'allowed_origins' => ['http://localhost:3000'],

'allowed_headers' => ['*'],
```

---

## 10. SUMMARY

Backend Laravel untuk sistem login mencakup:

1. **Database Schema**: Users table dengan role system, password reset tokens, login logs
2. **Models**: User model dengan relationships dan scopes, LoginLog untuk audit
3. **Controllers**: AuthController dengan lengkap (login, register, logout, refresh, password reset)
4. **Routes**: Public dan protected routes dengan role-based access
5. **Middleware**: Role checking middleware
6. **Frontend Integration**: Auth API service dengan TypeScript types
7. **Security**: Rate limiting, token expiration, CORS configuration

### Flow Lengkap:

```
User Login
  ↓ POST /api/v1/auth/login (email + password)
AuthController::login()
  ↓ Validate credentials
  ↓ Check user status
  ↓ Create Sanctum token
  ↓ Log login attempt
Response: { user, token, token_type }
  ↓
Frontend stores token
  ↓
Subsequent requests include: Authorization: Bearer {token}
  ↓
Protected routes accessible
```

### Default Test Accounts:

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@lhk.com | admin123 |
| Peserta | peserta@lhk.com | peserta123 |
| Komite Teknis | komite@lhk.com | komite123 |
