<?php

namespace App\Models;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Enums\SuppressionType;
use App\Support\CustomFieldKey;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * Standard fields that can be filled from forms and imports.
     */
    public const FIELDS = [
        'email', 'first_name', 'last_name', 'company', 'title', 'phone',
        'website', 'linkedin_url', 'city', 'country', 'timezone',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        ...self::FIELDS,
        'custom_fields',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'first_name' => null,
        'last_name' => null,
        'company' => null,
        'title' => null,
        'phone' => null,
        'website' => null,
        'linkedin_url' => null,
        'city' => null,
        'country' => null,
        'timezone' => null,
        'custom_fields' => null,
        'status' => 'new',
        'source' => 'manual',
        'last_contacted_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'status' => LeadStatus::class,
            'last_contacted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Lead $lead): void {
            $lead->email = Str::lower(trim($lead->email));
            $lead->email_domain = Str::after($lead->email, '@');

            if (is_array($lead->custom_fields)) {
                $fields = [];

                foreach ($lead->custom_fields as $key => $value) {
                    $key = CustomFieldKey::normalize((string) $key);

                    if ($key !== '' && ! in_array($key, self::FIELDS, true)) {
                        $fields[$key] = is_scalar($value) || $value === null ? $value : json_encode($value);
                    }
                }

                $lead->custom_fields = $fields === [] ? null : $fields;
            }
        });

        static::created(function (Lead $lead): void {
            if ($lead->source === 'manual') {
                $lead->logActivity(LeadActivityType::Created, 'Lead created');
            }
        });

        static::updated(function (Lead $lead): void {
            if ($lead->wasChanged('status')) {
                $lead->logActivity(LeadActivityType::StatusChanged, "Status changed to {$lead->status->getLabel()}", [
                    'from' => $lead->getOriginal('status')?->value,
                    'to' => $lead->status->value,
                ]);
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsToMany<LeadList, $this>
     */
    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(LeadList::class, 'lead_list_lead');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'lead_tag');
    }

    /**
     * @return HasMany<LeadActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('created_at')->latest('id');
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Values available as {{variables}} in emails: standard fields plus
     * custom fields (custom fields never override standard ones).
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $standard = [];

        foreach (self::FIELDS as $field) {
            $standard[$field] = (string) ($this->{$field} ?? '');
        }

        $standard['full_name'] = $this->fullName();

        $custom = array_map(fn ($value): string => (string) $value, $this->custom_fields ?? []);

        return $standard + $custom;
    }

    /**
     * Replace this lead's tags with the given names (created if new).
     *
     * @param  array<string>  $names
     */
    public function syncTagNames(array $names): void
    {
        $tagIds = Tag::idsForNames($this->workspace_id, $names);
        $changes = $this->tags()->sync($tagIds);

        if ($changes['attached'] !== []) {
            $this->logActivity(LeadActivityType::Tagged, 'Tagged '.Tag::whereKey($changes['attached'])->pluck('name')->implode(', '));
        }

        if ($changes['detached'] !== []) {
            $this->logActivity(LeadActivityType::Untagged, 'Removed tag '.Tag::whereKey($changes['detached'])->pluck('name')->implode(', '));
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function logActivity(LeadActivityType $type, string $description, array $properties = []): LeadActivity
    {
        $activity = new LeadActivity([
            'type' => $type,
            'description' => Str::limit($description, 250),
            'properties' => $properties === [] ? null : $properties,
        ]);

        $activity->workspace_id = $this->workspace_id;
        $activity->lead_id = $this->getKey();
        $activity->user_id = auth()->id();
        $activity->created_at = now();
        $activity->save();

        return $activity;
    }

    /**
     * @param  Builder<Lead>  $query
     */
    public function scopeWhereSuppressed(Builder $query): void
    {
        $query->whereExists(fn (QueryBuilder $sub) => static::matchingSuppressions($sub));
    }

    /**
     * Leads it's allowed to email. The sending engine must use this.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeWhereNotSuppressed(Builder $query): void
    {
        $query->whereNotExists(fn (QueryBuilder $sub) => static::matchingSuppressions($sub));
    }

    protected static function matchingSuppressions(QueryBuilder $sub): QueryBuilder
    {
        return $sub->selectRaw('1')
            ->from('suppressions')
            ->whereColumn('suppressions.workspace_id', 'leads.workspace_id')
            ->where(fn (QueryBuilder $match) => $match
                ->where(fn (QueryBuilder $email) => $email
                    ->where('suppressions.type', SuppressionType::Email->value)
                    ->whereColumn('suppressions.value', 'leads.email'))
                ->orWhere(fn (QueryBuilder $domain) => $domain
                    ->where('suppressions.type', SuppressionType::Domain->value)
                    ->whereColumn('suppressions.value', 'leads.email_domain')));
    }
}
