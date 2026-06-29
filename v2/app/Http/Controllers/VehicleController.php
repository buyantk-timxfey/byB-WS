<?php

namespace App\Http\Controllers;

use App\Models\CarWash;
use App\Models\FuelCardTopup;
use App\Models\FuelUp;
use App\Models\VehicleSetting;
use App\Models\VehicleTrip;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class VehicleController extends Controller
{
    private function settings(): VehicleSetting
    {
        return VehicleSetting::firstOrCreate(['id' => 1], ['tank_liters' => 60, 'consumption' => 8]);
    }

    public function index()
    {
        $s = $this->settings();
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $fuelMonth = (float) FuelUp::where('date', '>=', $monthStart)->sum('sum');
        $washMonth = (float) CarWash::where('date', '>=', $monthStart)->sum('sum');
        $kmMonth = (float) VehicleTrip::where('date', '>=', $monthStart)->sum('km');

        return Inertia::render('Transport', [
            'settings' => [
                'name' => $s->name, 'tank' => (float) $s->tank_liters, 'consumption' => (float) $s->consumption,
                'odometer' => (int) $s->odometer, 'fuel_left' => (float) $s->fuel_left, 'card_balance' => (float) $s->card_balance,
                'fuel_pct' => $s->fuelPct(), 'range_km' => $s->rangeKm(),
            ],
            'fuelUps' => FuelUp::orderByDesc('date')->orderByDesc('id')->get(),
            'trips' => VehicleTrip::orderByDesc('date')->orderByDesc('id')->get(),
            'washes' => CarWash::orderByDesc('date')->orderByDesc('id')->get(),
            'topups' => FuelCardTopup::orderByDesc('date')->orderByDesc('id')->get(),
            'monthSpend' => $fuelMonth + $washMonth,
            'monthKm' => $kmMonth,
        ]);
    }

    public function storeFuel(Request $r)
    {
        $d = $r->validate(['date' => 'required|date', 'azs' => 'nullable|string', 'liters' => 'required|numeric|min:0',
            'price' => 'required|numeric|min:0', 'odometer' => 'nullable|integer', 'paid_from' => 'in:card,cash']);
        $d['sum'] = round($d['liters'] * $d['price'], 2);
        FuelUp::create($d);
        $s = $this->settings();
        $s->fuel_left += $d['liters'];
        if (($d['paid_from'] ?? 'card') === 'card') {
            $s->card_balance -= $d['sum'];
        }
        if (! empty($d['odometer'])) {
            $s->odometer = max($s->odometer, $d['odometer']);
        }
        $s->save();

        return back();
    }

    public function storeTrip(Request $r)
    {
        $d = $r->validate(['date' => 'required|date', 'route' => 'required|string', 'km' => 'required|numeric|min:0',
            'fuel' => 'nullable|numeric|min:0', 'goal' => 'nullable|string']);
        $d['fuel'] = $d['fuel'] ?? round($d['km'] * $this->settings()->consumption / 100, 2);
        VehicleTrip::create($d);
        $s = $this->settings();
        $s->fuel_left = max(0, $s->fuel_left - $d['fuel']);
        $s->odometer += (int) $d['km'];
        $s->save();

        return back();
    }

    public function storeWash(Request $r)
    {
        $d = $r->validate(['date' => 'required|date', 'place' => 'nullable|string', 'type' => 'nullable|string', 'sum' => 'required|numeric|min:0']);
        CarWash::create($d);
        $s = $this->settings();
        $s->card_balance -= $d['sum'];
        $s->save();

        return back();
    }

    public function storeTopup(Request $r)
    {
        $d = $r->validate(['date' => 'required|date', 'sum' => 'required|numeric|min:0', 'source' => 'nullable|string']);
        FuelCardTopup::create($d);
        $s = $this->settings();
        $s->card_balance += $d['sum'];
        $s->save();

        return back();
    }

    public function updateSettings(Request $r)
    {
        $d = $r->validate(['name' => 'nullable|string', 'tank_liters' => 'required|numeric|min:1',
            'consumption' => 'required|numeric|min:0', 'odometer' => 'required|integer|min:0',
            'fuel_left' => 'required|numeric|min:0', 'card_balance' => 'required|numeric']);
        $this->settings()->update($d);

        return back();
    }
}
