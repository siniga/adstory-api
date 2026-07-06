<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdstoryShotImage extends Model
{
    protected $fillable = [
        'adstory_project_id',
        'adstory_scene_id',
        'adstory_shot_id',
        'version_number',
        'title',
        'prompt',
        'image_url',
        'storage_path',
        'status',
        'is_approved',
        'meta',
        // Legacy columns kept for backward compatibility.
        'thumbnail_url',
        'negative_prompt',
        'seed',
        'model',
        'generation_time_ms',
    ];

    protected function casts(): array
    {
        return [
            'is_approved' => 'boolean',
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AdstoryProject::class, 'adstory_project_id');
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(AdstoryScene::class, 'adstory_scene_id');
    }

    public function shot(): BelongsTo
    {
        return $this->belongsTo(AdstoryShot::class, 'adstory_shot_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'adstory_project_id' => $this->adstory_project_id,
            'adstory_scene_id' => $this->adstory_scene_id,
            'adstory_shot_id' => $this->adstory_shot_id,
            'version_number' => $this->version_number,
            'title' => $this->title,
            'prompt' => $this->prompt,
            'image_url' => $this->image_url,
            'storage_path' => $this->storage_path,
            'status' => $this->status,
            'is_approved' => $this->is_approved,
            'meta' => $this->meta ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
