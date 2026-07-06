<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AdstoryShot extends Model
{
    protected $fillable = [
        'adstory_project_id',
        'adstory_scene_id',
        'shot_number',
        'title',
        'description',
        'action',
        'dialogue',
        'shot_size',
        'camera_angle',
        'camera_movement',
        'composition',
        'lens',
        'lighting',
        'environment',
        'characters',
        'duration_seconds',
        'prompt',
        'image_prompt',
        'image_url',
        'image_status',
        'image_progress',
        'image_generation_started_at',
        'image_generation_completed_at',
        'image_retry_count',
        'generation_error',
        'order_index',
        'status',
        'meta',
        'selected_character_assets',
        'selected_environment_assets',
        'composition_preset',
        'cinematography_preset',
        'lighting_preset',
        'storyboard_settings',
    ];

    protected function casts(): array
    {
        return [
            'characters' => 'array',
            'meta' => 'array',
            'selected_character_assets' => 'array',
            'selected_environment_assets' => 'array',
            'composition_preset' => 'array',
            'cinematography_preset' => 'array',
            'lighting_preset' => 'array',
            'storyboard_settings' => 'array',
            'image_progress' => 'integer',
            'image_generation_started_at' => 'datetime',
            'image_generation_completed_at' => 'datetime',
            'image_retry_count' => 'integer',
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

    public function shotImages(): HasMany
    {
        return $this->hasMany(AdstoryShotImage::class, 'adstory_shot_id')
            ->orderBy('version_number');
    }

    public function images(): HasMany
    {
        return $this->shotImages();
    }

    public function approvedImage(): HasOne
    {
        return $this->hasOne(AdstoryShotImage::class, 'adstory_shot_id')
            ->where('is_approved', true)
            ->latest('version_number');
    }

    public function markStoryboardImageQueued(): void
    {
        $this->image_status = 'queued';
        $this->image_progress = 0;
        $this->generation_error = null;
        $this->image_generation_started_at = null;
        $this->image_generation_completed_at = null;
        $this->save();
    }

    public function markStoryboardImageGenerating(): void
    {
        $this->image_status = 'generating';
        $this->image_generation_started_at = now();
        $this->image_generation_completed_at = null;
        $this->save();
    }

    public function markStoryboardImageCompleted(string $imageUrl, string $imagePrompt): void
    {
        $this->image_url = $imageUrl;
        $this->image_status = 'completed';
        $this->image_prompt = $imagePrompt;
        $this->image_progress = 100;
        $this->generation_error = null;
        $this->image_generation_completed_at = now();
        $this->save();
    }

    public function markStoryboardImageFailed(string $error): void
    {
        $this->image_status = 'failed';
        $this->generation_error = $error;
        $this->image_generation_completed_at = now();
        $this->save();
    }

    public function markStoryboardImageCancelled(): void
    {
        if ($this->image_status === 'completed' && ! empty($this->image_url)) {
            return;
        }

        $this->image_status = 'pending';
        $this->image_progress = 0;
        $this->generation_error = null;
        $this->image_generation_started_at = null;
        $this->image_generation_completed_at = null;
        $this->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $meta = $this->meta ?? [];

        $data = [
            'id' => $this->id,
            'adstory_project_id' => $this->adstory_project_id,
            'adstory_scene_id' => $this->adstory_scene_id,
            'scene_id' => $this->adstory_scene_id,
            'scene_number' => $meta['scene_number'] ?? $this->scene?->scene_number,
            'shot_number' => $this->shot_number,
            'title' => $this->title,
            'description' => $this->description,
            'action' => $this->action,
            'dialogue' => $this->dialogue,
            'shot_size' => $this->shot_size,
            'camera_angle' => $this->camera_angle,
            'camera_movement' => $this->camera_movement,
            'composition' => $this->composition,
            'lens' => $this->lens,
            'lighting' => $this->lighting,
            'environment' => $this->environment,
            'characters' => $this->characters ?? [],
            'duration_seconds' => $this->duration_seconds,
            'prompt' => $this->prompt,
            'image_prompt' => $this->image_prompt,
            'image_url' => $this->image_url,
            'image_status' => $this->image_status,
            'image_progress' => (int) ($this->image_progress ?? 0),
            'image_generation_started_at' => $this->image_generation_started_at?->toIso8601String(),
            'image_generation_completed_at' => $this->image_generation_completed_at?->toIso8601String(),
            'image_retry_count' => (int) ($this->image_retry_count ?? 0),
            'generation_error' => $this->generation_error,
            'order_index' => $this->order_index,
            'status' => $this->status,
            'meta' => $meta,
            'mood' => $meta['mood'] ?? null,
            'selected_character_assets' => $this->selected_character_assets ?? [],
            'selected_environment_assets' => $this->selected_environment_assets ?? [],
            'composition_preset' => $this->composition_preset ?? null,
            'cinematography_preset' => $this->cinematography_preset ?? null,
            'lighting_preset' => $this->lighting_preset ?? null,
            'storyboard_settings' => $this->storyboard_settings ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->relationLoaded('shotImages')) {
            $images = $this->shotImages
                ->map(fn (AdstoryShotImage $image) => $image->toApiArray())
                ->values()
                ->all();

            $data['images'] = $images;
            $data['shot_images'] = $images;
        }

        if ($this->relationLoaded('approvedImage')) {
            $data['approved_image'] = $this->approvedImage?->toApiArray();
        }

        return $data;
    }
}
