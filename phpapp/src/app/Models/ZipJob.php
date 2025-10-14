<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ZipJob extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'status',
        'progress',
        'document_ids',
        'disk',
        'archive_disk',
        'result_path',
        'result_filename',
        'error',
        'completed_at',
    ];

    protected $casts = [
        'document_ids' => 'array',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ZipJob $job) {
            if (empty($job->id)) {
                $job->id = (string) Str::uuid();
            }
        });
    }
}
