<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable(['part_id', 'stock_source', 'closing_time', 'urutan'])]
class KeseiPart extends Model
{
    protected function casts(): array
    {
        return [
            'closing_time' => 'datetime:H:i',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * A Kesei row can belong to more than one Pattern Board.
     */
    public function patternBoards(): BelongsToMany
    {
        return $this->belongsToMany(PatternBoard::class)->orderBy('name');
    }

    /**
     * The part_no(s) whose Stock Part All readings feed this row's Timeline
     * Stok, summed. Falls back to this row's own part_no when no override is
     * set.
     *
     * @return array<int, string>
     */
    public function sourcePartNos(): array
    {
        $raw = trim((string) $this->stock_source);

        if ($raw !== '') {
            return Str::of($raw)
                ->explode(',')
                ->map(fn ($part) => trim($part))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $own = $this->part?->part_no;

        return $own !== null && $own !== '' ? [$own] : [];
    }
}
