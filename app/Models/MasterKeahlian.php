<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterKeahlian extends Model
{
    protected $table = 'master_keahlian';

    protected $fillable = [
        'nama',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Format text to Pascal Case with Spaces (Title Case)
     * e.g. "ahli tata ruang" -> "Ahli Tata Ruang"
     */
    public static function formatPascalCase(?string $text): ?string
    {
        if (empty($text)) {
            return null;
        }

        $cleaned = trim(preg_replace('/\s+/', ' ', $text));
        if (empty($cleaned)) {
            return null;
        }

        return mb_convert_case($cleaned, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Format and register expertise into master_keahlian table if not yet present
     */
    public static function recordIfNew(?string $nama): ?string
    {
        $formatted = self::formatPascalCase($nama);
        if (empty($formatted)) {
            return null;
        }

        self::firstOrCreate(
            ['nama' => $formatted],
            ['is_default' => false]
        );

        return $formatted;
    }

    /**
     * Format and register multiple expertises (array or comma-separated string)
     * Returns a clean comma-separated string: "Ahli Mutu Udara, Ahli Mutu Air"
     */
    public static function recordMultipleIfNew($input): ?string
    {
        if (empty($input)) {
            return null;
        }

        if (is_string($input)) {
            if (str_starts_with(trim($input), '[') && str_ends_with(trim($input), ']')) {
                $decoded = json_decode($input, true);
                $items = is_array($decoded) ? $decoded : explode(',', $input);
            } else {
                $items = explode(',', $input);
            }
        } elseif (is_array($input)) {
            $items = $input;
        } else {
            return null;
        }

        $formattedList = [];
        foreach ($items as $item) {
            $rec = self::recordIfNew(is_string($item) ? $item : '');
            if (!empty($rec) && !in_array($rec, $formattedList, true)) {
                $formattedList[] = $rec;
            }
        }

        return !empty($formattedList) ? implode(', ', $formattedList) : null;
    }
}
