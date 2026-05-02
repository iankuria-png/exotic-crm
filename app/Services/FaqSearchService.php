<?php

namespace App\Services;

use App\Models\Faq\Article;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FaqSearchService
{
    public function apply(Builder $query, ?string $search): Builder
    {
        $term = trim((string) $search);
        if ($term === '') {
            return $query;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            return $query
                ->whereRaw(
                    'MATCH (title, summary, body) AGAINST (? IN NATURAL LANGUAGE MODE)',
                    [$term]
                )
                ->orderByRaw(
                    'MATCH (title, summary, body) AGAINST (? IN NATURAL LANGUAGE MODE) DESC',
                    [$term]
                );
        }

        $tokens = collect(preg_split('/\s+/', $term) ?: [])
            ->map(static fn ($value) => trim((string) $value))
            ->filter()
            ->values();

        return $query->where(function (Builder $builder) use ($tokens, $term) {
            if ($tokens->isEmpty()) {
                $builder
                    ->orWhere('title', 'like', '%' . $term . '%')
                    ->orWhere('summary', 'like', '%' . $term . '%')
                    ->orWhere('body', 'like', '%' . $term . '%');

                return;
            }

            foreach ($tokens as $token) {
                $builder->where(function (Builder $subQuery) use ($token) {
                    $subQuery
                        ->where('title', 'like', '%' . $token . '%')
                        ->orWhere('summary', 'like', '%' . $token . '%')
                        ->orWhere('body', 'like', '%' . $token . '%');
                });
            }
        })->orderByRaw(
            '(case when title like ? then 0 when summary like ? then 1 else 2 end)',
            ['%' . $term . '%', '%' . $term . '%']
        );
    }

    public function query(?string $search): Builder
    {
        return $this->apply(Article::query(), $search);
    }
}
