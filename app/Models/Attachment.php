<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    protected $fillable = [
        'attachable_type', 'attachable_id', 'title', 'disk', 'path',
        'original_name', 'mime', 'size', 'uploaded_by',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function humanSize(): string
    {
        $size = (int) $this->size;
        if ($size < 1024) {
            return $size.' B';
        }
        if ($size < 1048576) {
            return round($size / 1024, 1).' KB';
        }

        return round($size / 1048576, 1).' MB';
    }
}
