<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AfricanCountriesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            ['name' => 'Algeria', 'currency_name' => 'Dinar', 'currency_code' => 'DZD', 'currency_symbol' => 'DA'],
            ['name' => 'Angola', 'currency_name' => 'Kwanza', 'currency_code' => 'AOA', 'currency_symbol' => 'KZ'],
            ['name' => 'Benin Republic', 'currency_name' => 'CFA Franc', 'currency_code' => 'XOF', 'currency_symbol' => 'XOF'],
            ['name' => 'Botswana', 'currency_name' => 'Pula', 'currency_code' => 'BWP', 'currency_symbol' => 'P'],
            ['name' => 'Burundi', 'currency_name' => 'Burundi Franc', 'currency_code' => 'BIF', 'currency_symbol' => 'FBu'],
            ['name' => 'Burkina Faso', 'currency_name' => 'CFA Franc', 'currency_code' => 'XOF', 'currency_symbol' => 'CFA'],
            ['name' => 'Egypt', 'currency_name' => 'Pound', 'currency_code' => 'EGP', 'currency_symbol' => 'E£'],
            ['name' => 'DR Congo', 'currency_name' => 'Francs', 'currency_code' => 'CDF', 'currency_symbol' => 'FC'],
            ['name' => 'Djibouti', 'currency_name' => 'Djibouti Franc', 'currency_code' => 'DJF', 'currency_symbol' => 'Fdj'],
            ['name' => 'Equatorial Guinea', 'currency_name' => 'CFA Franc BEAC', 'currency_code' => 'XAF', 'currency_symbol' => 'FCFA'],
            ['name' => 'Cameroon', 'currency_name' => 'CFA Franc BEAC', 'currency_code' => 'XAF', 'currency_symbol' => 'FCFA'],
            ['name' => 'Cape Verde', 'currency_name' => 'Cape Verde Escudo', 'currency_code' => 'CVE', 'currency_symbol' => '$'],
            ['name' => 'Central African Republic', 'currency_name' => 'CFA Franc', 'currency_code' => 'XAF', 'currency_symbol' => 'FCFA'],
            ['name' => 'Chad', 'currency_name' => 'CFA Franc', 'currency_code' => 'XAF', 'currency_symbol' => 'FCFA'],
            ['name' => 'Comoros', 'currency_name' => 'Comoros Franc', 'currency_code' => 'KMF', 'currency_symbol' => 'CF'],
            ['name' => 'Cote d\'Ivoire', 'currency_name' => 'CFA Franc', 'currency_code' => 'XOF', 'currency_symbol' => 'CFA'],
            ['name' => 'Eritrea', 'currency_name' => 'Eritrean Nakfa', 'currency_code' => 'ERN', 'currency_symbol' => 'Nkf'],
            ['name' => 'Ethiopia', 'currency_name' => 'Birr', 'currency_code' => 'ETB', 'currency_symbol' => 'Br'],
            ['name' => 'Gabon', 'currency_name' => 'CFA Franc', 'currency_code' => 'XAF', 'currency_symbol' => 'FCFA'],
            ['name' => 'Gambia', 'currency_name' => 'Dalasi', 'currency_code' => 'GMD', 'currency_symbol' => 'D'],
            ['name' => 'Ghana', 'currency_name' => 'Cedi', 'currency_code' => 'GHS', 'currency_symbol' => 'GH₵'],
            ['name' => 'Libya', 'currency_name' => 'Dinar', 'currency_code' => 'LYD', 'currency_symbol' => 'LD'],
            ['name' => 'Madagascar', 'currency_name' => 'Malagasy ariary', 'currency_code' => 'MGA', 'currency_symbol' => 'Ar'],
            ['name' => 'Malawi', 'currency_name' => 'Kwacha', 'currency_code' => 'MWK', 'currency_symbol' => 'K'],
            ['name' => 'Liberia', 'currency_name' => 'Dollar', 'currency_code' => 'LRD', 'currency_symbol' => 'L$, LD$'],
            ['name' => 'Guinea-Bissau', 'currency_name' => 'Guinea-Bissau Peso', 'currency_code' => 'GWP', 'currency_symbol' => 'CFA'],
            ['name' => 'Guinea', 'currency_name' => 'Franc', 'currency_code' => 'GNF', 'currency_symbol' => 'FG'],
            ['name' => 'Kenya', 'currency_name' => 'Shillings', 'currency_code' => 'KES', 'currency_symbol' => 'KSh'],
            ['name' => 'Lesotho', 'currency_name' => 'Loti', 'currency_code' => 'LSL', 'currency_symbol' => 'L or M'],
            ['name' => 'Mali', 'currency_name' => 'CFA Franc', 'currency_code' => 'XOF', 'currency_symbol' => 'CFA'],
            ['name' => 'Mauritania', 'currency_name' => 'Ouguiya', 'currency_code' => 'MRO', 'currency_symbol' => ''],
            ['name' => 'Mauritius', 'currency_name' => 'Rupees', 'currency_code' => 'MUR', 'currency_symbol' => 'Rs'],
            ['name' => 'Morocco', 'currency_name' => 'Dirham', 'currency_code' => 'MAD', 'currency_symbol' => 'DH'],
            ['name' => 'Mozambique', 'currency_name' => 'Metical', 'currency_code' => 'MZN', 'currency_symbol' => 'MT'],
            ['name' => 'Namibia', 'currency_name' => 'Dollar', 'currency_code' => 'NAD', 'currency_symbol' => '$, N$'],
            ['name' => 'Niger', 'currency_name' => 'CFA Franc', 'currency_code' => 'XOF', 'currency_symbol' => 'CFA'],
            ['name' => 'Nigeria', 'currency_name' => 'Naira', 'currency_code' => 'NGN', 'currency_symbol' => 'N'],
            ['name' => 'Republic of the Congo', 'currency_name' => 'Franc', 'currency_code' => 'XAF', 'currency_symbol' => 'FCFA'],
            ['name' => 'Zimbabwe', 'currency_name' => 'Dollar', 'currency_code' => 'ZWD', 'currency_symbol' => 'K'],
            ['name' => 'Sierra Leone', 'currency_name' => 'Leone', 'currency_code' => 'SLL', 'currency_symbol' => 'Sl'],
            ['name' => 'Somalia', 'currency_name' => 'Shillings', 'currency_code' => 'SOS', 'currency_symbol' => 'Sh'],
            ['name' => 'Seychelles', 'currency_name' => 'Rupees', 'currency_code' => 'SCR', 'currency_symbol' => 'SR'],
            ['name' => 'Senegal', 'currency_name' => 'CFA Franc', 'currency_code' => 'XOF', 'currency_symbol' => 'CFA'],
            ['name' => 'São Tomé and Principe', 'currency_name' => 'Dobra', 'currency_code' => 'STD', 'currency_symbol' => 'Db'],
            ['name' => 'Rwanda', 'currency_name' => 'Franc', 'currency_code' => 'RWF', 'currency_symbol' => 'FRw, RF, R₣'],
            ['name' => 'South Sudan', 'currency_name' => 'Pound', 'currency_code' => 'SSP', 'currency_symbol' => 'SS£'],
            ['name' => 'Sudan', 'currency_name' => 'Pound', 'currency_code' => 'SDG', 'currency_symbol' => 'SDG'],
            ['name' => 'Swaziland', 'currency_name' => 'Lilangeni', 'currency_code' => 'SZL', 'currency_symbol' => ''],
            ['name' => 'South Africa', 'currency_name' => 'Rand', 'currency_code' => 'ZAR', 'currency_symbol' => 'R'],
            ['name' => 'Tanzania', 'currency_name' => 'Shillings', 'currency_code' => 'TZS', 'currency_symbol' => 'TSh'],
            ['name' => 'Togo', 'currency_name' => 'CFA Franc', 'currency_code' => 'XOF', 'currency_symbol' => 'CFA'],
            ['name' => 'Tunisia', 'currency_name' => 'Dinar', 'currency_code' => 'TND', 'currency_symbol' => 'DT'],
            ['name' => 'Zambia', 'currency_name' => 'Kwacha', 'currency_code' => 'ZMW', 'currency_symbol' => 'K'],
            ['name' => 'Uganda', 'currency_name' => 'Shillings', 'currency_code' => 'UGX', 'currency_symbol' => 'USh'],
        ];

        DB::table('african_countries')->insert($countries);
    }
}