<?php

namespace App\Models;

use App\Enums\GermanLevel;
use App\Enums\Situation;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable(['name', 'email', 'password', 'city', 'situation', 'arrival_date', 'german_level', 'speaks', 'onboarded_at', 'avatar_path'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable, TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'arrival_date' => 'date',
            'speaks' => 'array',
            'onboarded_at' => 'datetime',
            'situation' => Situation::class,
            'german_level' => GermanLevel::class,
        ];
    }

    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    /** @return HasMany<UserPlace, $this> */
    public function places(): HasMany
    {
        return $this->hasMany(UserPlace::class)->orderBy('sort_order');
    }

    /** @return HasMany<UserTask, $this> */
    public function userTasks(): HasMany
    {
        return $this->hasMany(UserTask::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** @return HasMany<SpotCheckin, $this> */
    public function spotCheckins(): HasMany
    {
        return $this->hasMany(SpotCheckin::class);
    }

    /** @return HasMany<Event, $this> */
    public function organisedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'organiser_id');
    }

    /** @return BelongsToMany<Event, $this> */
    public function attendingEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_attendees')
            ->withPivot('joined_at', 'reminded_at')
            ->withTimestamps();
    }

    /** @return HasMany<LanguagePartner, $this> */
    public function languagePartnersRequested(): HasMany
    {
        return $this->hasMany(LanguagePartner::class, 'requester_id');
    }

    /** @return HasMany<LanguagePartner, $this> */
    public function languagePartnersReceived(): HasMany
    {
        return $this->hasMany(LanguagePartner::class, 'receiver_id');
    }

    /** @return BelongsToMany<Conversation, $this> */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('joined_at');
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /** @return HasMany<Routine, $this> */
    public function routines(): HasMany
    {
        return $this->hasMany(Routine::class);
    }

    /** @return HasMany<UserEvent, $this> */
    public function behaviorEvents(): HasMany
    {
        return $this->hasMany(UserEvent::class);
    }

    /** @return HasMany<SlotMonitor, $this> */
    public function slotMonitors(): HasMany
    {
        return $this->hasMany(SlotMonitor::class);
    }
}
