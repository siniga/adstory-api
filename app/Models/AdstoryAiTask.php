<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AdstoryAiTask extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_GENERATE_SCENE = 'generate_scene';

    public const TYPE_GENERATE_EPISODE_SCENES = 'generate_episode_scenes';

    public const TYPE_GENERATE_SHOTS_FOR_SCENE = 'generate_shots_for_scene';

    public const TYPE_GENERATE_STORYBOARD_IMAGE_FOR_SHOT = 'generate_storyboard_image_for_shot';

    public const TYPE_EXTRACT_CHARACTERS = 'extract_characters';

    public const TYPE_GENERATE_CHARACTER_IMAGE = 'generate_character_image';

    public const TYPE_EXTRACT_ENVIRONMENTS = 'extract_environments';

    public const TYPE_GENERATE_ENVIRONMENT_IMAGE = 'generate_environment_image';

    /** @var list<string> */
    public const SCENE_BLOCKED_TYPES = [
        self::TYPE_GENERATE_SCENE,
    ];

    protected $fillable = [
        'adstory_project_id',
        'taskable_type',
        'taskable_id',
        'type',
        'status',
        'priority',
        'attempt_count',
        'max_attempts',
        'payload',
        'result',
        'error',
        'started_at',
        'completed_at',
        'failed_at',
        'locked_at',
        'locked_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'locked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AdstoryProject::class, 'adstory_project_id');
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'taskable_type' => $this->taskable_type,
            'taskable_id' => $this->taskable_id,
            'priority' => $this->priority,
            'attempt_count' => $this->attempt_count,
            'max_attempts' => $this->max_attempts,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
        ];
    }
}
