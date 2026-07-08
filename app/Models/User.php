<?php

namespace App\Models;

use App\Enums\GermanLevel;
use App\Enums\Situation;
use App\Enums\TransportMode;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable(['name', 'email', 'password', 'city', 'situation', 'arrival_date', 'german_level', 'speaks', 'veedel', 'is_eu', 'has_deutschlandticket', 'transport_mode', 'bureaucracy_path', 'profile_attributes', 'interests', 'onboarded_at', 'avatar_path', 'is_admin', 'social_id', 'social_provider'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable, TwoFactorAuthenticatable;

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_admin;
    }

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
            'is_eu' => 'boolean',
            'has_deutschlandticket' => 'boolean',
            'transport_mode' => TransportMode::class,
            'profile_attributes' => 'array',
            'interests' => 'array',
        ];
    }

    /**
     * Set one long-tail profile attribute with an append-only audit entry —
     * the log powers "why am I seeing this" and later eligibility math.
     */
    public function setProfileAttribute(string $attribute, mixed $value, string $source = 'system'): void
    {
        $current = $this->profile_attributes ?? [];
        $old = $current[$attribute] ?? null;

        if ($old === $value) {
            return;
        }

        $this->attributeChanges()->create([
            'attribute' => $attribute,
            'old_value' => $old,
            'new_value' => $value,
            'source' => $source,
        ]);

        $current[$attribute] = $value;
        $this->update(['profile_attributes' => $current]);
    }

    /** @return HasMany<ProfileAttributeChange, $this> */
    public function attributeChanges(): HasMany
    {
        return $this->hasMany(ProfileAttributeChange::class);
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

    /** @return HasMany<SpotFeedback, $this> */
    public function spotFeedback(): HasMany
    {
        return $this->hasMany(SpotFeedback::class);
    }

    /** @return HasMany<UserTask, $this> */
    public function userTasks(): HasMany
    {
        return $this->hasMany(UserTask::class);
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

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /** @return HasMany<UserEvent, $this> */
    public function behaviorEvents(): HasMany
    {
        return $this->hasMany(UserEvent::class);
    }

    /** @return HasOne<NotificationPreference, $this> */
    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    /** @return HasOne<UserSetting, $this> */
    public function userSetting(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /** @return HasOne<ActiveTrip, $this> */
    public function activeTrip(): HasOne
    {
        return $this->hasOne(ActiveTrip::class);
    }

    /** Check if user wants a specific notification type */
    public function wantsNotification(string $type): bool
    {
        $prefs = $this->notificationPreference?->preferences
            ?? NotificationPreference::defaults();

        return $prefs[$type] ?? true;
    }

    /** Get a user setting value with fallback to default */
    public function setting(string $key): mixed
    {
        $settings = $this->userSetting?->settings ?? UserSetting::defaults();

        return $settings[$key] ?? UserSetting::defaults()[$key] ?? null;
    }
}
