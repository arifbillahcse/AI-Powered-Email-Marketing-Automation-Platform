<?php

namespace App\Models;

use App\Enums\AiContentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPromptTemplate extends Model
{
    public const TONES = ['friendly' => 'Friendly', 'professional' => 'Professional', 'casual' => 'Casual', 'direct' => 'Direct', 'witty' => 'Witty'];

    public const LENGTHS = ['short' => 'Short', 'medium' => 'Medium', 'long' => 'Long'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'instructions',
        'tone',
        'language',
        'length',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tone' => 'friendly',
        'language' => 'English',
        'length' => 'short',
    ];

    protected function casts(): array
    {
        return [
            'type' => AiContentType::class,
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
