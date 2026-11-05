<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeatureManagerController extends Controller
{
    public function index(): View
    {
        foreach (FeatureFlag::AVAILABLE as $key => $information) {
            FeatureFlag::query()->firstOrCreate(['key' => $key], ['enabled' => true]);
        }

        return view('dashboard.features', [
            'features' => FeatureFlag::query()->orderBy('key')->paginate(10),
            'available' => FeatureFlag::AVAILABLE,
        ]);
    }

    public function update(Request $request, FeatureFlag $feature): RedirectResponse
    {
        abort_unless(array_key_exists($feature->key, FeatureFlag::AVAILABLE), 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $feature->update(['enabled' => (bool) $data['enabled']]);

        return back()->with('status', FeatureFlag::AVAILABLE[$feature->key]['label'].' updated.');
    }
}
