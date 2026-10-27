<?php

namespace App\Services\Revenue;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/** Correlated expressions keep queue rows, exports and dashboard counts identical without multiplying receipts. */
class PaymentAttributionQuery
{
    public static function expressions(Builder $query): array
    {
        $db = $query->getConnection();
        $p = $db->getQueryGrammar()->wrapTable('payments');
        $a = $db->getQueryGrammar()->wrapTable('payment_attributions');
        $c = $db->getQueryGrammar()->wrapTable('clients');
        $concat = fn ($parts) => $db->getDriverName() === 'sqlite' ? '('.implode(' || ', $parts).')' : 'CONCAT('.implode(',', $parts).')';
        $live = "EXISTS (SELECT 1 FROM $c c WHERE c.id=$p.client_id AND c.platform_id=$p.platform_id)";
        $liveKey = $concat(["$p.platform_id", "':client:'", "$p.client_id"]);
        $liveDate = "(SELECT c.created_at FROM $c c WHERE c.id=$p.client_id AND c.platform_id=$p.platform_id)";
        if (! Schema::hasTable('payment_attributions')) {
            return ["CASE WHEN $live THEN 'current' ELSE 'unresolved' END", "CASE WHEN $live THEN $liveKey ELSE NULL END", $liveDate];
        }
        $sub = fn ($field) => "(SELECT a.$field FROM $a a WHERE a.payment_id=$p.id AND a.platform_id=$p.platform_id)";
        $has = "EXISTS (SELECT 1 FROM $a a WHERE a.payment_id=$p.id AND a.platform_id=$p.platform_id)";
        $id = $sub('original_client_id');
        $wp = $sub('wp_post_id');
        $state = $sub('attribution_state');
        $conflict = "($p.client_id IS NOT NULL AND $id IS NOT NULL AND $p.client_id <> $id)";
        $known = "($state IN ('current','archived') AND ($id IS NOT NULL OR $wp IS NOT NULL) AND NOT $conflict)";
        $key = "CASE WHEN $id IS NOT NULL THEN ".$concat(["$p.platform_id", "':client:'", $id]).' ELSE '.$concat(["$p.platform_id", "':wp:'", $wp]).' END';

        return ["CASE WHEN $has THEN CASE WHEN $conflict THEN 'disputed' ELSE $state END WHEN $live THEN 'current' ELSE 'unresolved' END",
            "CASE WHEN $has THEN CASE WHEN $known THEN $key ELSE NULL END WHEN $live THEN $liveKey ELSE NULL END",
            "CASE WHEN $has THEN ".$sub('client_first_seen_at')." ELSE $liveDate END"];
    }

    public static function matched(Builder $query, bool $matched = true): Builder
    {
        $key = self::expressions($query)[1];

        return $query->whereRaw("($key) IS ".($matched ? 'NOT NULL' : 'NULL'));
    }

    public static function state(Builder $query, string $state): Builder
    {
        return $query->whereRaw('('.self::expressions($query)[0].') = ?', [$state]);
    }

    public static function clientsCount(Builder $query): int
    {
        $key = self::expressions($query)[1];

        return (int) $query->toBase()->select([])->selectRaw("COUNT(DISTINCT ($key)) AS subjects")->value('subjects');
    }
}
