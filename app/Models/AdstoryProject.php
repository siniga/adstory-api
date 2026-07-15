<?php

namespace App\Models;

use App\Services\Adstory\AdstoryProjectFullLoaderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdstoryProject extends Model
{
    protected $fillable = [
        'title',
        'story',
        'script',
        'screenplay',
        'visual_style',
        'cover_image_url',
        'current_step',
        'status',
        'scene_generation_status',
        'scene_generation_total',
        'scene_generation_completed',
        'scene_generation_failed',
        'scene_generation_started_at',
        'scene_generation_finished_at',
        'shot_generation_status',
        'shot_generation_total',
        'shot_generation_completed',
        'shot_generation_failed',
        'shot_generation_started_at',
        'shot_generation_finished_at',
        'character_generation_status',
        'character_generation_total',
        'character_generation_completed',
        'character_generation_failed',
        'character_generation_started_at',
        'character_generation_finished_at',
        'environment_generation_status',
        'environment_generation_total',
        'environment_generation_completed',
        'environment_generation_failed',
        'environment_generation_started_at',
        'environment_generation_finished_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'scene_generation_started_at' => 'datetime',
            'scene_generation_finished_at' => 'datetime',
            'shot_generation_started_at' => 'datetime',
            'shot_generation_finished_at' => 'datetime',
            'character_generation_started_at' => 'datetime',
            'character_generation_finished_at' => 'datetime',
            'environment_generation_started_at' => 'datetime',
            'environment_generation_finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(AdstoryScene::class)->orderBy('order_index')->orderBy('id');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(AdstoryEpisode::class)->orderBy('episode_number')->orderBy('id');
    }

    public function shots(): HasMany
    {
        return $this->hasMany(AdstoryShot::class)->orderBy('order_index')->orderBy('id');
    }

    public function characters(): HasMany
    {
        return $this->hasMany(AdstoryCharacter::class)->orderBy('order_index')->orderBy('id');
    }

    public function environments(): HasMany
    {
        return $this->hasMany(AdstoryEnvironment::class)->orderBy('order_index')->orderBy('id');
    }

    public function shotImages(): HasMany
    {
        return $this->hasMany(AdstoryShotImage::class, 'adstory_project_id');
    }

    public function characterAssets(): HasMany
    {
        return $this->hasMany(AdstoryCharacterAsset::class, 'adstory_project_id');
    }

    public function environmentAssets(): HasMany
    {
        return $this->hasMany(AdstoryEnvironmentAsset::class, 'adstory_project_id');
    }

    public function aiTasks(): HasMany
    {
        return $this->hasMany(AdstoryAiTask::class, 'adstory_project_id');
    }

    /**
     * Lightweight shape for project list endpoints — excludes story/script/screenplay/meta.
     *
     * @return array<string, mixed>
     */
    public function toListApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'visual_style' => $this->visual_style,
            'style' => $this->visual_style,
            'cover_image_url' => $this->cover_image_url,
            'current_step' => $this->current_step,
            'status' => $this->status,
            'scene_generation_status' => $this->scene_generation_status,
            'scene_generation_total' => (int) ($this->scene_generation_total ?? 0),
            'scene_generation_completed' => (int) ($this->scene_generation_completed ?? 0),
            'scene_generation_failed' => (int) ($this->scene_generation_failed ?? 0),
            'scene_generation_started_at' => $this->scene_generation_started_at?->toIso8601String(),
            'scene_generation_finished_at' => $this->scene_generation_finished_at?->toIso8601String(),
            'shot_generation_status' => $this->shot_generation_status,
            'shot_generation_total' => (int) ($this->shot_generation_total ?? 0),
            'shot_generation_completed' => (int) ($this->shot_generation_completed ?? 0),
            'shot_generation_failed' => (int) ($this->shot_generation_failed ?? 0),
            'shot_generation_started_at' => $this->shot_generation_started_at?->toIso8601String(),
            'shot_generation_finished_at' => $this->shot_generation_finished_at?->toIso8601String(),
            'character_generation_status' => $this->character_generation_status,
            'character_generation_total' => (int) ($this->character_generation_total ?? 0),
            'character_generation_completed' => (int) ($this->character_generation_completed ?? 0),
            'character_generation_failed' => (int) ($this->character_generation_failed ?? 0),
            'character_generation_started_at' => $this->character_generation_started_at?->toIso8601String(),
            'character_generation_finished_at' => $this->character_generation_finished_at?->toIso8601String(),
            'episode_count' => (int) ($this->episodes_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'story' => $this->story,
            'story_text' => $this->story,
            'script' => $this->script,
            'screenplay' => $this->screenplay,
            'visual_style' => $this->visual_style,
            'style' => $this->visual_style,
            'cover_image_url' => $this->cover_image_url,
            'current_step' => $this->current_step,
            'status' => $this->status,
            'scene_generation_status' => $this->scene_generation_status,
            'scene_generation_total' => (int) ($this->scene_generation_total ?? 0),
            'scene_generation_completed' => (int) ($this->scene_generation_completed ?? 0),
            'scene_generation_failed' => (int) ($this->scene_generation_failed ?? 0),
            'scene_generation_started_at' => $this->scene_generation_started_at?->toIso8601String(),
            'scene_generation_finished_at' => $this->scene_generation_finished_at?->toIso8601String(),
            'shot_generation_status' => $this->shot_generation_status,
            'shot_generation_total' => (int) ($this->shot_generation_total ?? 0),
            'shot_generation_completed' => (int) ($this->shot_generation_completed ?? 0),
            'shot_generation_failed' => (int) ($this->shot_generation_failed ?? 0),
            'shot_generation_started_at' => $this->shot_generation_started_at?->toIso8601String(),
            'shot_generation_finished_at' => $this->shot_generation_finished_at?->toIso8601String(),
            'character_generation_status' => $this->character_generation_status,
            'character_generation_total' => (int) ($this->character_generation_total ?? 0),
            'character_generation_completed' => (int) ($this->character_generation_completed ?? 0),
            'character_generation_failed' => (int) ($this->character_generation_failed ?? 0),
            'character_generation_started_at' => $this->character_generation_started_at?->toIso8601String(),
            'character_generation_finished_at' => $this->character_generation_finished_at?->toIso8601String(),
            'meta' => $this->meta ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @deprecated Use AdstoryProjectFullLoaderService via GET /projects/{project}/full?include=...
     *
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    public function toFullApiArray(array $includes = ['scenes', 'shots', 'characters', 'environments']): array
    {
        return app(AdstoryProjectFullLoaderService::class)->load($this, $includes);
    }
}
