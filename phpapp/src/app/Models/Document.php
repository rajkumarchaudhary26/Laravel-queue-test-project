<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'extension',
        'size',
        'mime_type',
    ];
}
