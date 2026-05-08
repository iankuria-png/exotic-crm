<?php

namespace App\Services;

use App\Exports\PaymentExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\LazyCollection;

class PaymentExportDataService
{
    public function __construct(
        private readonly PaymentQueueQueryBuilder $paymentQueueQueryBuilder
    ) {
    }

    public function build(Request $request, array $validated, array $columns, array $formatOptions = []): LazyCollection
    {
        $selectedColumns = array_values(array_intersect($columns, array_keys(PaymentExporter::columnDefinitions())));
        $eagerLoads = $this->eagerLoadsForColumns($selectedColumns);
        $context = $this->paymentQueueQueryBuilder->resolveContext($request, $validated);
        $query = $this->paymentQueueQueryBuilder->buildRowsQuery(
            $request,
            $validated,
            $eagerLoads,
            false,
            $context
        );

        $count = (clone $query)->count();
        if ($count > 50000) {
            throw new HttpResponseException(response()->json([
                'message' => 'Export scope exceeds 50,000 rows. Narrow the filters and try again.',
            ], 422));
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->lazy(500);
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<string, string>
     */
    private function eagerLoadsForColumns(array $columns): array
    {
        $with = [];

        if (in_array('client_name', $columns, true)) {
            $with['client'] = 'client:id,name';
        }

        if (in_array('deal_subscription_lifecycle', $columns, true)) {
            $with['deal'] = 'deal:id,client_id,subscription_lifecycle';
        }

        if (in_array('product_name', $columns, true)) {
            $with['product'] = 'product:id,name';
        }

        return array_values($with);
    }
}
