<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The tenant. Every customer-owned record belongs to a workspace.
 */
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'timezone',
        'company_name',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
    ];

    /**
     * Mirrors the column defaults so freshly created models expose them.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'UTC',
        'company_name' => null,
        'address_line1' => null,
        'address_line2' => null,
        'city' => null,
        'state' => null,
        'postal_code' => null,
        'country' => null,
    ];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace): void {
            $workspace->slug ??= static::uniqueSlug($workspace->name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $base = Str::limit($base, 40, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (static::where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * @return BelongsToMany<User, $this, Membership, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<WorkspaceInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /**
     * @return HasMany<EmailAccount, $this>
     */
    public function emailAccounts(): HasMany
    {
        return $this->hasMany(EmailAccount::class);
    }

    /**
     * @return HasMany<SendingDomain, $this>
     */
    public function sendingDomains(): HasMany
    {
        return $this->hasMany(SendingDomain::class);
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return HasMany<LeadList, $this>
     */
    public function leadLists(): HasMany
    {
        return $this->hasMany(LeadList::class);
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * @return HasMany<Segment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    /**
     * @return HasMany<Suppression, $this>
     */
    public function suppressions(): HasMany
    {
        return $this->hasMany(Suppression::class);
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * @return HasMany<AiPromptTemplate, $this>
     */
    public function aiPromptTemplates(): HasMany
    {
        return $this->hasMany(AiPromptTemplate::class);
    }

    /**
     * @return HasMany<AiGeneration, $this>
     */
    public function aiGenerations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    public function addMember(User $user, WorkspaceRole $role): void
    {
        $this->members()->syncWithoutDetaching([
            $user->getKey() => ['role' => $role->value],
        ]);
    }

    public function hasMember(User $user): bool
    {
        return $this->memberships()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Whether the CAN-SPAM footer address is complete enough to send campaigns.
     */
    public function hasMailingAddress(): bool
    {
        return filled($this->address_line1)
            && filled($this->city)
            && filled($this->country);
    }

    /**
     * Single-line postal address for email footers.
     */
    public function mailingAddress(): ?string
    {
        if (! $this->hasMailingAddress()) {
            return null;
        }

        return collect([
            $this->company_name,
            $this->address_line1,
            $this->address_line2,
            trim(implode(' ', array_filter([$this->city, $this->state, $this->postal_code]))),
            $this->country,
        ])->filter()->implode(', ');
    }
}
