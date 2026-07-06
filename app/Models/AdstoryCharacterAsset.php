<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdstoryCharacterAsset extends Model
{
    protected $fillable = [
        'adstory_project_id',
        'adstory_character_id',
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

    public function character(): BelongsTo
    {
        return $this->belongsTo(AdstoryCharacter::class, 'adstory_character_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'adstory_project_id' => $this->adstory_project_id,
            'adstory_character_id' => $this->adstory_character_id,
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

    /**
     * Slim asset payload for Storyboard asset picker.
     *
     * @return array<string, mixed>
     */
    public function toStoryboardArray(): array
    {
        return [
            'id' => $this->id,
            'asset_type' => $this->asset_type,
            'title' => $this->title,
            'image_url' => $this->image_url,
            'is_primary' => $this->is_primary,
            'status' => $this->status,
        ];
    }
}
