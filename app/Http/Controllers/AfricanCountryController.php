<?php

namespace App\Http\Controllers;

use App\Models\AfricanCountry;
use Illuminate\Http\Request;

class AfricanCountryController extends Controller
{
    /**
     * Display a listing of African countries.
     */
    public function index()
    {
        $countries = AfricanCountry::active()->orderBy('name')->get();
        
        return response()->json([
            'status' => 'success',
            'data' => $countries,
            'count' => $countries->count()
        ]);
    }

    /**
     * Get country by currency code.
     */
    public function getByCurrencyCode($currencyCode)
    {
        $countries = AfricanCountry::where('currency_code', $currencyCode)->get();
        
        return response()->json([
            'status' => 'success',
            'currency_code' => $currencyCode,
            'data' => $countries,
            'count' => $countries->count()
        ]);
    }

    /**
     * Search countries by name.
     */
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2'
        ]);

        $query = $request->input('query');
        $countries = AfricanCountry::where('name', 'like', "%{$query}%")
            ->orWhere('currency_name', 'like', "%{$query}%")
            ->orWhere('currency_code', 'like', "%{$query}%")
            ->get();

        return response()->json([
            'status' => 'success',
            'query' => $query,
            'data' => $countries,
            'count' => $countries->count()
        ]);
    }
}