<?php

namespace App\Models;

use App\Services\Adstory\AdstorySceneGenerationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdstoryScene extends Model
{
    /**
     * When true, allows completed → pending/queued/generating on save (explicit scene regeneration).
     */
    public bool $allowStatusDowngrade = false;

    /**
     * Scene environment stores a descriptive environment prompt for the scene
     * (e.g. "Modern East African university campus with landscaped courtyards..."),
     * not just a short location label.
     *
     * TODO: In a future version, scenes will reference a generated Environment entity
     * via environment_id. The detailed description will live in the Environment library,
     * and multiple scenes will be able to reuse the same environment.
     */
    protected $fillable = [
        'adstory_project_id',
        'adstory_episode_id',
        'scene_number',
        'title',
        'slug',
        'location',
        'environment',
        'time_of_day',
        'description',
        'screenplay_excerpt',
        'mood',
        'visual_style',
        'order_index',
        'status',
        'generation_error',
        'generated_at',
        'shot_generation_status',
        'shot_generation_error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'generated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AdstoryScene $scene) {
            if (! $scene->exists) {
                return;
            }

            $original = $scene->getOriginal('status');
            $new = $scene->status;

            if ($original !== AdstorySceneGenerationService::SCENE_STATUS_COMPLETED) {
                return;
            }

            $downgradeStatuses = [
                AdstorySceneGenerationService::SCENE_STATUS_PENDING,
                AdstorySceneGenerationService::SCENE_STATUS_QUEUED,
                AdstorySceneGenerationService::SCENE_STATUS_GENERATING,
            ];

            if (! in_array($new, $downgradeStatuses, true)) {
                return;
            }

            $force = $scene->allowStatusDowngrade || (bool) request()->input('force', false);

            if ($force) {
                return;
            }

            Log::warning('Blocked scene status downgrade during shot generation', [
                'scene_id' => $scene->id,
                'project_id' => $scene->adstory_project_id,
                'original_status' => $original,
                'attempted_status' => $new,
            ]);

            $scene->status = $original;
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AdstoryProject::class, 'adstory_project_id');
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(AdstoryEpisode::class, 'adstory_episode_id');
    }

    public function shots(): HasMany
    {
        return $this->hasMany(AdstoryShot::class)->orderBy('order_index')->orderBy('id');
    }

    public function shotImages(): HasMany
    {
        return $this->hasMany(AdstoryShotImage::class, 'adstory_scene_id');
    }

    public function markShotGenerationQueued(): void
    {
        $this->applyShotGenerationStatus('queued', null);
    }

    public function markShotGenerationGenerating(): void
    {
        $this->applyShotGenerationStatus('generating', null);
    }

    public function markShotGenerationCompleted(): void
    {
        $this->applyShotGenerationStatus('completed', null);
    }

    public function markShotGenerationFailed(string $error): void
    {
        $this->applyShotGenerationStatus('failed', $error);
    }

    public function markShotGenerationCancelled(): void
    {
        if ($this->shots()->exists()) {
            $this->applyShotGenerationStatus('completed', null);

            return;
        }

        $this->applyShotGenerationStatus('not_started', null);
    }

    private function applyShotGenerationStatus(string $status, ?string $error): void
    {
        if (Schema::hasColumn('adstory_scenes', 'shot_generation_status')) {
            $this->shot_generation_status = $status;
            $this->shot_generation_error = $error;
        } else {
            $meta = is_array($this->meta ?? null) ? $this->meta : [];
            $meta['shot_generation_status'] = $status;

            if ($error !== null) {
                $meta['shot_generation_error'] = $error;
            } else {
                unset($meta['shot_generation_error']);
            }

            $this->meta = $meta;
        }

        $this->save();

        Log::info("Shot generation status updated: {$status}", [
            'scene_id' => $this->id,
            'project_id' => $this->adstory_project_id,
            'shot_generation_status' => $status,
            'scene_status' => $this->status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $meta = $this->meta ?? [];

        return [
            'id' => $this->id,
            'adstory_project_id' => $this->adstory_project_id,
            'adstory_episode_id' => $this->adstory_episode_id,
            'scene_number' => $this->scene_number,
            'title' => $this->title,
            'slug' => $this->slug,
            'location' => $this->location,
            'time_of_day' => $this->time_of_day,
            'description' => $this->description,
            'screenplay_excerpt' => $this->screenplay_excerpt,
            'mood' => $this->mood,
            'visual_style' => $this->visual_style,
            'order_index' => $this->order_index,
            'status' => $this->status,
            'generation_error' => $this->generation_error,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'shot_generation_status' => $this->shot_generation_status ?? ($meta['shot_generation_status'] ?? null),
            'shot_generation_error' => $this->shot_generation_error ?? ($meta['shot_generation_error'] ?? null),
            'meta' => $meta,
            'characters' => $meta['characters'] ?? [],
            // Full descriptive environment prompt; falls back to legacy meta storage.
            'environment' => $this->environment ?? $meta['environment'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toProgressArray(): array
    {
        return [
            'id' => $this->id,
            'scene_number' => $this->scene_number,
            'title' => $this->title,
            'status' => $this->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toFailedProgressArray(): array
    {
        return [
            'id' => $this->id,
            'scene_number' => $this->scene_number,
            'title' => $this->title,
            'status' => $this->status,
            'generation_error' => $this->generation_error,
        ];
    }
}
