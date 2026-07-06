<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdstoryEnvironmentAsset extends Model
{
    protected $fillable = [
        'adstory_project_id',
        'adstory_environment_id',
        'asset_type',
        'title',
        'image_url',
        'storage_path',
        'prompt',
        'is_primary',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AdstoryProject::class, 'adstory_project_id');
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(AdstoryEnvironment::class, 'adstory_environment_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'adstory_project_id' => $this->adstory_project_id,
            'adstory_environment_id' => $this->adstory_environment_id,
            'asset_type' => $this->asset_type,
            'title' => $this->title,
            'image_url' => $this->image_url,
            'storage_path' => $this->storage_path,
            'prompt' => $this->prompt,
            'is_primary' => $this->is_primary,
            'status' => $this->status,
            'meta' => $this->meta ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
