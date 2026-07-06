<?php

namespace App\Models;

use App\Services\Adstory\AdstoryCharacterAssetService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdstoryCharacter extends Model
{
    protected $fillable = [
        'adstory_project_id',
        'name',
        'role',
        'description',
        'personality',
        'appearance',
        'wardrobe',
        'age',
        'gender',
        'image_url',
        'image_status',
        'generation_error',
        'prompt',
        'references',
        'order_index',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'references' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AdstoryProject::class, 'adstory_project_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(AdstoryCharacterAsset::class, 'adstory_character_id')
            ->orderByDesc('is_primary')
            ->orderBy('id');
    }

    public function markImageGenerationQueued(): void
    {
        $this->image_status = 'queued';
        $this->status = $this->status === 'completed' ? 'completed' : 'queued';
        $this->save();
    }

    public function markImageGenerationGenerating(): void
    {
        $this->image_status = 'generating';
        $this->save();
    }

    public function markImageGenerationCompleted(): void
    {
        $this->image_status = 'completed';
        $this->status = 'completed';
        $this->generation_error = null;
        $this->save();
    }

    public function markImageGenerationFailed(string $error): void
    {
        $this->image_status = 'failed';
        $this->status = 'failed';
        $this->generation_error = $error;
        $this->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $meta = $this->meta ?? [];
        $legacyId = $meta['legacy_id'] ?? $meta['id'] ?? (string) $this->id;
        $references = $this->normalizeReferencesForApi($this->references ?? []);

        $data = [
            'id' => $legacyId,
            'db_id' => $this->id,
            'adstory_project_id' => $this->adstory_project_id,
            'name' => $this->name,
            'role' => $this->role,
            'description' => $this->description,
            'personality' => $this->personality,
            'appearance' => $this->appearance,
            'wardrobe' => $this->wardrobe,
            'age' => $this->age,
            'gender' => $this->gender,
            'image_url' => $this->image_url,
            'image_status' => $this->image_status,
            'generation_error' => $this->generation_error,
            'prompt' => $this->prompt,
            'references' => $references,
            'order_index' => $this->order_index,
            'status' => $this->status,
            'meta' => $meta,
            'importance' => $meta['importance'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->relationLoaded('assets')) {
            $data['assets'] = app(AdstoryCharacterAssetService::class)
                ->getStoryboardAssets($this);
        }

        return $data;
    }

    /**
     * @param  array<int, array<string, mixed>>  $references
     * @return array<int, array<string, mixed>>
     */
    private function normalizeReferencesForApi(array $references): array
    {
        return array_values(array_map(function (array $reference) {
            $type = $reference['type'] ?? $reference['reference_type'] ?? null;

            return [
                'type' => $type,
                'reference_type' => $type,
                'title' => $reference['title'] ?? null,
                'image_url' => $reference['image_url'] ?? null,
                'prompt' => $reference['prompt'] ?? null,
                'created_at' => $reference['created_at'] ?? null,
                'status' => $reference['status'] ?? (($reference['image_url'] ?? null) ? 'completed' : 'pending'),
            ];
        }, $references));
    }
}
