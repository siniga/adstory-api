<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdstoryEpisode extends Model
{
    public const MAX_SCENES_PER_EPISODE = 5;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_SCENES_GENERATING = 'scenes_generating';

    public const STATUS_SCENES_COMPLETED = 'scenes_completed';

    public const STATUS_SHOTS_GENERATING = 'shots_generating';

    public const STATUS_SHOTS_COMPLETED = 'shots_completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'adstory_project_id',
        'episode_number',
        'title',
        'summary',
        'estimated_scene_count',
        'start_scene_number',
        'end_scene_number',
        'status',
        'scene_generation_status',
        'scene_generation_error',
        'shot_generation_status',
        'shot_generation_error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AdstoryProject::class, 'adstory_project_id');
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(AdstoryScene::class, 'adstory_episode_id')
            ->orderBy('order_index')
            ->orderBy('id');
    }

    public function markSceneGenerationCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_SCENES_COMPLETED,
            'scene_generation_status' => 'completed',
            'scene_generation_error' => null,
        ]);
    }

    public function markSceneGenerationFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'scene_generation_status' => 'failed',
            'scene_generation_error' => $error,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'adstory_project_id' => $this->adstory_project_id,
            'episode_number' => $this->episode_number,
            'title' => $this->title,
            'summary' => $this->summary,
            'estimated_scene_count' => (int) ($this->estimated_scene_count ?? 0),
            'start_scene_number' => $this->start_scene_number,
            'end_scene_number' => $this->end_scene_number,
            'status' => $this->status,
            'scene_generation_status' => $this->scene_generation_status,
            'scene_generation_error' => $this->scene_generation_error,
            'shot_generation_status' => $this->shot_generation_status,
            'shot_generation_error' => $this->shot_generation_error,
            'meta' => $this->meta ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(int $sceneCount = 0, int $shotCount = 0): array
    {
        return [
            'id' => $this->id,
            'episode_number' => $this->episode_number,
            'title' => $this->title,
            'summary' => $this->summary,
            'start_scene_number' => $this->start_scene_number,
            'end_scene_number' => $this->end_scene_number,
            'status' => $this->status,
            'scene_generation_status' => $this->scene_generation_status,
            'shot_generation_status' => $this->shot_generation_status,
            'scene_count' => $sceneCount,
            'shot_count' => $shotCount,
        ];
    }
}
