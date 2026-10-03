<?php

namespace App\Services\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Support\CustomFieldKey;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Turns segment rules into a lead query. Uses only the query builder
 * (whereLike etc.) so it behaves the same on PostgreSQL and MySQL.
 */
class SegmentQuery
{
    public const TEXT_FIELDS = [
        'email' => 'Email',
        'email_domain' => 'Email domain',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'company' => 'Company',
        'title' => 'Job title',
        'city' => 'City',
        'country' => 'Country',
    ];

    public const TEXT_OPERATORS = [
        'equals' => 'is',
        'not_equals' => 'is not',
        'contains' => 'contains',
        'not_contains' => 'does not contain',
        'starts_with' => 'starts with',
        'ends_with' => 'ends with',
        'is_empty' => 'is empty',
        'is_not_empty' => 'is not empty',
    ];

    /**
     * Field options for the segment builder.
     *
     * @return array<string, string>
     */
    public static function fieldOptions(): array
    {
        return self::TEXT_FIELDS + [
            'custom' => 'Custom field…',
            'status' => 'Status',
            'list' => 'List',
            'tag' => 'Tag',
            'created_at' => 'Added',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function operatorOptions(?string $field): array
    {
        return match ($field) {
            'status' => ['equals' => 'is', 'not_equals' => 'is not'],
            'list' => ['in' => 'is in', 'not_in' => 'is not in'],
            'tag' => ['has' => 'has', 'not_has' => 'does not have'],
            'created_at' => ['after' => 'after', 'before' => 'before'],
            default => self::TEXT_OPERATORS,
        };
    }

    public static function needsValue(?string $operator): bool
    {
        return ! in_array($operator, ['is_empty', 'is_not_empty'], true);
    }

    /**
     * @param  Builder<Lead>  $query
     * @param  array<int, array<string, mixed>>  $rules
     * @return Builder<Lead>
     */
    public function apply(Builder $query, array $rules, string $match, int $workspaceId): Builder
    {
        $rules = array_values(array_filter($rules, fn ($rule): bool => is_array($rule) && filled($rule['field'] ?? null) && filled($rule['operator'] ?? null)));

        if ($rules === []) {
            return $query;
        }

        return $query->where(function (Builder $group) use ($rules, $match, $workspaceId): void {
            foreach ($rules as $rule) {
                $method = $match === 'any' ? 'orWhere' : 'where';
                $group->{$method}(fn (Builder $condition) => $this->applyRule($condition, $rule, $workspaceId));
            }
        });
    }

    /**
     * @param  Builder<Lead>  $query
     * @param  array<string, mixed>  $rule
     */
    protected function applyRule(Builder $query, array $rule, int $workspaceId): void
    {
        $field = $rule['field'];
        $operator = $rule['operator'];
        $value = $rule['value'] ?? null;

        match (true) {
            $field === 'status' => $query->where('status', $operator === 'not_equals' ? '!=' : '=', $this->status($value)->value),
            $field === 'list' => $operator === 'not_in'
                ? $query->whereDoesntHave('lists', fn (Builder $list) => $list->whereKey($value)->where('workspace_id', $workspaceId))
                : $query->whereHas('lists', fn (Builder $list) => $list->whereKey($value)->where('workspace_id', $workspaceId)),
            $field === 'tag' => $operator === 'not_has'
                ? $query->whereDoesntHave('tags', fn (Builder $tag) => $tag->where('name', mb_strtolower(trim((string) $value)))->where('workspace_id', $workspaceId))
                : $query->whereHas('tags', fn (Builder $tag) => $tag->where('name', mb_strtolower(trim((string) $value)))->where('workspace_id', $workspaceId)),
            $field === 'created_at' => $query->whereDate('leads.created_at', $operator === 'before' ? '<' : '>', (string) $value),
            $field === 'custom' => $this->applyText($query, 'custom_fields->'.CustomFieldKey::normalize((string) ($rule['key'] ?? '')), $operator, $value),
            array_key_exists($field, self::TEXT_FIELDS) => $this->applyText($query, "leads.{$field}", $operator, $value),
            default => throw new InvalidArgumentException("Unknown segment field [{$field}]."),
        };
    }

    /**
     * @param  Builder<Lead>  $query
     */
    protected function applyText(Builder $query, string $column, string $operator, mixed $value): void
    {
        $value = $this->escapeLike(trim((string) $value));

        match ($operator) {
            'equals' => $query->whereLike($column, $value),
            'not_equals' => $query->where(fn (Builder $q) => $q->whereNotLike($column, $value)->orWhereNull($column)),
            'contains' => $query->whereLike($column, "%{$value}%"),
            'not_contains' => $query->where(fn (Builder $q) => $q->whereNotLike($column, "%{$value}%")->orWhereNull($column)),
            'starts_with' => $query->whereLike($column, "{$value}%"),
            'ends_with' => $query->whereLike($column, "%{$value}"),
            'is_empty' => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '')),
            'is_not_empty' => $query->whereNotNull($column)->where($column, '!=', ''),
            default => throw new InvalidArgumentException("Unknown text operator [{$operator}]."),
        };
    }

    protected function status(mixed $value): LeadStatus
    {
        return $value instanceof LeadStatus ? $value : LeadStatus::from((string) $value);
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
