<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * App\Models\User
 *
 * @property string $username
 * @property string $password
 * @property string|null $nama_lengkap
 * @property string|null $gelar_depan
 * @property string|null $gelar_blk
 * @property string|null $tmp_lahir
 * @property \Illuminate\Support\Carbon|null $tgl_lahir
 * @property string|null $no_induk
 * @property string|null $no_ktp
 * @property string|null $pendidikan_terakhir
 * @property string|null $email
 * @property string|null $alamat
 * @property string|null $RT
 * @property string|null $RW
 * @property string|null $kelurahan
 * @property string|null $kecamatan
 * @property string|null $kota
 * @property string|null $propinsi
 * @property string|null $no_telp
 * @property string|null $foto
 * @property string $level
 * @property string $blokir
 * @property string|null $id_session
 * @property \Illuminate\Support\Carbon $waktu
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection|int $notifications_count
 * @property-read int|\Laravel\Sanctum\PersonalAccessToken[]|int $tokens_count
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'username';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be timestamped.
     * Disabled because users table uses 'waktu' column instead of created_at/updated_at
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'password',
        'nama_lengkap',
        'gelar_depan',
        'gelar_blk',
        'tmp_lahir',
        'tgl_lahir',
        'no_induk',
        'no_ktp',
        'pendidikan_terakhir',
        'email',
        'alamat',
        'no_telp',
        'foto',
        'level',
        'blokir',
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
        'tgl_lahir' => 'date',
        'waktu' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Role mapping from existing 'level' field
     */
    public function getRoleAttribute(): string
    {
        $level = strtolower($this->level ?? 'user');

        // Mapping level ke role yang sesuai
        return match($level) {
            'user' => 'USER',
            'peserta' => 'USER',
            'komite-teknis' => 'KOMITE-TEKNIS',
            'penguji' => 'PENGUJI',
            'admin' => 'ADMIN',
            'superadmin' => 'ADMIN',
            default => strtoupper($level),
        };
    }

    /**
     * Check if user is komite teknis
     */
    public function isKomiteTeknis(): bool
    {
        return in_array($this->level, ['komite-teknis'], true);
    }

    /**
     * Check if user is penguji
     */
    public function isPenguji(): bool
    {
        return in_array($this->level, ['penguji'], true);
    }

    /**
     * Get status attribute (mapping from 'blokir' field)
     */
    public function getStatusAttribute(): string
    {
        return $this->blokir === 'N' ? 'ACTIVE' : 'SUSPENDED';
    }

    /**
     * Get the name for the user.
     */
    public function getNameAttribute(): string
    {
        return $this->nama_lengkap ?? $this->username;
    }

    /**
     * Get the phone number for the user.
     */
    public function getNoHpAttribute(): string
    {
        return $this->no_telp ?? '';
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return in_array($this->level, ['admin', 'superadmin'], true);
    }

    /**
     * Check if user is peserta (participant)
     */
    public function isPeserta(): bool
    {
        return in_array($this->level, ['user', 'peserta'], true);
    }

    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return $this->blokir === 'N';
    }

    /**
     * Scope for active users
     */
    public function scopeActive($query)
    {
        return $query->where('blokir', 'N');
    }

    /**
     * Scope for role filtering
     */
    public function scopeWithRole($query, $role)
    {
        return $query->where('level', strtolower($role));
    }

    /**
     * Find user by KTP or phone number
     */
    public function scopeFindByKtpOrPhone($query, $identifier)
    {
        return $query->where(function ($q) use ($identifier) {
            $q->where('no_ktp', $identifier)
              ->orWhere('no_telp', $identifier);
        });
    }

    /**
     * Get the unique identifier for the user.
     *
     * @return mixed
     */
    public function getAuthIdentifier()
    {
        return $this->username;
    }

    /**
     * Get the value of the model's primary key.
     * Override for Sanctum compatibility with string primary key
     *
     * @return mixed
     */
    public function getKey()
    {
        return $this->username;
    }
}
