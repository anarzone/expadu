<?php

namespace App\Models;

use App\Enums\DeadlineType;
use App\Enums\Urgency;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'situation', 'phase', 'deadline_type', 'deadline_days', 'urgency', 'links', 'documents_required'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'situation' => 'array',
            'links' => 'array',
            'documents_required' => 'array',
            'deadline_type' => DeadlineType::class,
            'urgency' => Urgency::class,
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_tasks')
            ->withPivot('completed_at', 'snoozed_until', 'notes')
            ->withTimestamps();
    }

    /** @return HasMany<UserTask, $this> */
    public function userTasks(): HasMany
    {
        return $this->hasMany(UserTask::class);
    }
}
