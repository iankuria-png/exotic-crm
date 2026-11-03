<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Services\Tours\CheckoutService;
use Illuminate\Http\Request;

class TourController extends Controller
{
    public function __construct(private readonly CheckoutService $service) {}

    private function home(Request $r)
    {
        return $r->attributes->get('tour_platform');
    }

    public function config(Request $r)
    {
        $this->service->owner($this->home($r), $r->all());
        $config = $this->service->configuration($this->home($r));

        return response()->json(['revision' => $config->revision, 'settings' => $config->settings, 'environment' => $config->settings['environment'], 'methods' => $this->service->methods($this->home($r), $config->settings['environment'])]);
    }

    public function catalog(Request $r)
    {
        return response()->json($this->service->catalog($this->home($r), $r->all()));
    }

    public function quote(Request $r)
    {
        return response()->json($this->service->quote($this->home($r), $r->all()));
    }

    public function intent(Request $r)
    {
        return response()->json($this->service->intent($this->home($r), $r->all(), $r));
    }

    public function status(Request $r, string $ref)
    {
        return response()->json($this->service->status($this->home($r), $ref, $r->all()));
    }

    public function cancel(Request $r, string $ref)
    {
        return response()->json($this->service->cancel($this->home($r), $ref, $r->all()));
    }
}
