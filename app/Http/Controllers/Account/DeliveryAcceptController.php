<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Statuslabel;
use Illuminate\Http\Request;
use App\Models\ReturnRequest;

class DeliveryAcceptController extends Controller
{
    public function show($assetId)
    {
        $asset = Asset::findOrFail($assetId);
        return view('account.accept.accept-delivery', compact('asset'));
    }

    public function store(Request $request, $assetId)
    {
        $request->validate([
            'receiver_name' => 'required|string|max:255',
        ]);

        $asset = Asset::findOrFail($assetId);
        
        $hasOpenReturn = ReturnRequest::where('asset_id', $asset->id)
	    ->whereNull('canceled_at')
	    ->whereNull('closed_at')
	    ->exists();

	if ($hasOpenReturn) {
	    return redirect()->back()->with('error', 'This asset has an open return request.');
	}

	if (auth()->user()?->location_id) {
	    $asset->location_id = auth()->user()->location_id;
	}

        // Append delivery note to asset notes
        $existingNotes = $asset->notes ?? '';
        $asset->notes = trim($existingNotes . "\n\nDelivery accepted by: " . $request->receiver_name . " on " . now());

        // Set status to "With Department"
        $withDeptId = Statuslabel::where('name', 'With Department')->value('id');
        if ($withDeptId) {
            $asset->status_id = $withDeptId;
        }

        $asset->save();

        return redirect()->route('hardware.show', $assetId)
            ->with('success', 'Delivery accepted successfully.');
    }
}

