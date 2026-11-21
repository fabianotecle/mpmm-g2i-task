<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeneralContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'area',
        'key',
        'content',
        'actions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'actions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public static function active(string $area, string $key): ?self
    {
        return self::where('area', $area)
            ->where('key', $key)
            ->where('is_active', true)
            ->latest()
            ->first();
    }
}
