<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\ReturnRequest;
use App\Notifications\ReturnStatusNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use App\Events\CheckoutableCheckedIn;
use App\Models\Location;
use App\Models\CheckoutAcceptance;
use App\Enums\ActionType;
use App\Models\Actionlog;


class ReturnsController extends Controller
{
    private function warehouseGroupNames(): array
    {
        return ['Warehouse keeper'];
    }

    private function secretaryGroupNames(): array
    {
        return ['Secretary'];
    }

    private function warehouseRecipients()
    {
        $allowedGroups = $this->warehouseGroupNames();

        return \App\Models\User::query()
            ->whereHas('groups', function ($g) use ($allowedGroups) {
                $g->whereIn('name', $allowedGroups);
            })
            ->get();
    }

    public function index()
	{
	    $user = auth()->user();

	    $isAdmin = $user && (
		Gate::allows('admin') ||
		Gate::allows('superadmin') ||
		(method_exists($user, 'isSuperUser') && $user->isSuperUser())
	    );

	    $inAllowedGroup = $user && $user->groups()
		->whereIn('name', $this->warehouseGroupNames())
		->exists();

	    if (!($isAdmin || $inAllowedGroup)) {
		abort(403);
	    }

	    $returns = ReturnRequest::query()
		->whereNull('canceled_at')
		->whereNull('closed_at')
		->whereNull('checked_in_at')
		->latest('requested_at')
		->with('asset')
		->get();

	    return view('returns.index', compact('returns'));
	}

    public function store(Request $request, Asset $asset)
    {
        $user = auth()->user();
        
        $hasPendingAcceptance = CheckoutAcceptance::query()
	    ->where('checkoutable_type', Asset::class)
	    ->where('checkoutable_id', $asset->id)
	    ->where('assigned_to_id', $asset->assigned_to)
	    ->whereNull('accepted_at')
	    ->whereNull('declined_at')
	    ->exists();

	if ($hasPendingAcceptance) {
	    return back()->with(
		'error',
		'This file must be accepted before it can be returned to Archive.'
	    );
	}

        $exists = ReturnRequest::where('asset_id', $asset->id)
            ->whereNull('canceled_at')
            ->whereNull('closed_at')
            ->exists();

        if ($exists) {
            return back()->with('success', 'Return request already exists.');
        }

        $return = ReturnRequest::create([
	    'asset_id'      => $asset->id,
	    'requested_by'  => $user?->id,
	    'requested_at'  => now(),
	    'in_transit_at' => now(),
	]);
	
	$logaction = new Actionlog();
	$logaction->item_id = $asset->id;
	$logaction->item_type = Asset::class;
	$logaction->created_by = $user?->id;
	$logaction->created_at = now();

	if (!empty($user?->location_id)) {
	    $logaction->location_id = $user->location_id;
	}

	$logaction->note = 'Return to Archive requested — awaiting Archive acceptance.';
	$logaction->logaction(ActionType::Requested);

	$inTransitId = \App\Models\Statuslabel::where('name', 'In Transit')->value('id');
	if ($inTransitId) {
	    $asset->status_id = $inTransitId;
	    $asset->save();
	}

	$recipients = $this->warehouseRecipients();
	Notification::send($recipients, new ReturnStatusNotification('requested', $return, $asset, $user));

        return back()->with('success', 'Return request sent to Warehouse.');
    }

    public function markReceived(ReturnRequest $return)
	{
	    $user = auth()->user();
	    $canReceive = $user && (
		Gate::allows('superadmin') ||
		Gate::allows('admin') ||
		$user->groups()->whereIn('name', $this->warehouseGroupNames())->exists()
	    );

	    if (!$canReceive) {
		abort(403);
	    }

	    if ($return->canceled_at || $return->received_at || $return->closed_at || $return->checked_in_at) {
		return back()->with('error', 'Return is closed.');
	    }

	    $return->received_at = now();
	    $return->save();
	    
	    $asset = $return->asset;

	    $logaction = new Actionlog();
	    $logaction->item_id = $asset->id;
            $logaction->item_type = Asset::class;
            $logaction->created_by = $user?->id;
	    $logaction->created_at = now();

	    if (!empty($user?->location_id)) {
		    $logaction->location_id = $user->location_id;
	     }

	     $logaction->note = 'Return received by Archive.';
	     $logaction->logaction(ActionType::Accepted);
	    
	    $requester = $return->requester;
	    if ($requester) {
		$requester->notify(new ReturnStatusNotification('received', $return, $return->asset, $user));
	    }

	    return back()->with('success', 'Marked as received.');
	}

    public function close(ReturnRequest $return)
    {
        $user = auth()->user();

        $canClose = $user && (
            Gate::allows('admin') ||
            Gate::allows('superadmin') ||
            (method_exists($user, 'isSuperUser') && $user->isSuperUser()) ||
            $user->groups()->whereIn('name', $this->warehouseGroupNames())->exists()
        );

        if (!$canClose) {
            abort(403);
        }

        if (!$return->received_at) {
            return back()->with('error', 'Cannot close before Mark Received.');
        }

        $return->checked_in_at = $return->checked_in_at ?? now();
        $return->closed_at = now();
        $return->save();

        return back()->with('success', 'Return closed.');
    }

    public function rows()
	{
	    $me = auth()->user();

	    $returns = ReturnRequest::with('asset')
		->whereNull('canceled_at')
		->whereNull('closed_at')
		->whereNull('checked_in_at')
		->orderByDesc('requested_at')
		->get();

	    $isSecretary = $me && $me->groups()
		->whereIn('name', $this->secretaryGroupNames())
		->exists();

	    $canWarehouse = $me && (
		Gate::allows('admin') ||
		Gate::allows('superadmin') ||
		(method_exists($me, 'isSuperUser') && $me->isSuperUser()) ||
		$me->groups()->whereIn('name', $this->warehouseGroupNames())->exists()
	    );

	    return view('returns._rows', compact('returns', 'isSecretary', 'canWarehouse'));
	}
	
	public function bulkMarkReceived(Request $request)
	{
	    $user = auth()->user();

	    $canReceive = $user && (
		Gate::allows('superadmin') ||
		Gate::allows('admin') ||
		$user->groups()->whereIn('name', $this->warehouseGroupNames())->exists()
	    );

	    if (!$canReceive) {
		abort(403);
	    }

	    $ids = $request->input('selected_returns', []);

	    if (!is_array($ids) || count($ids) === 0) {
		return back()->with('error', 'Please select at least one return.');
	    }

	    $returns = ReturnRequest::with('asset', 'requester')
		->whereIn('id', $ids)
		->whereNull('canceled_at')
		->whereNull('closed_at')
		->whereNull('checked_in_at')
		->get();

	    if ($returns->isEmpty()) {
		return back()->with('error', 'No valid returns found.');
	    }

	    $count = 0;
	    $firstReturn = null;
	    $firstAsset = null;
	    $requesters = collect();

	    foreach ($returns as $return) {
		if ($return->received_at || empty($return->in_transit_at)) {
		    continue;
		}

		$return->received_at = now();
		$return->save();
		
		$asset = $return->asset;

		if ($asset) {
		    $logaction = new Actionlog();
		    $logaction->item_id = $asset->id;
		    $logaction->item_type = Asset::class;
		    $logaction->created_by = $user?->id;
		    $logaction->created_at = now();

		    if (!empty($user?->location_id)) {
			$logaction->location_id = $user->location_id;
		    }

		    $logaction->note = 'Return received by Archive.';
		    $logaction->logaction(ActionType::Accepted);
		}

		if (!$firstReturn) {
		    $firstReturn = $return;
		    $firstAsset = $return->asset;
		}

		if ($return->requester) {
		    $requesters->push($return->requester);
		}

		$count++;
	    }

	    if ($count === 0) {
		return back()->with('error', 'No returns were marked as received.');
	    }

	    $uniqueRequesters = $requesters->unique('id');

	    foreach ($uniqueRequesters as $requester) {
		$requester->notify(
		    new ReturnStatusNotification('received', $firstReturn, $firstAsset, $user, $count)
		);
	    }

	    return back()->with('success', $count . ' returns marked as received.');
	}
	
	public function bulkCheckin(Request $request)
	{
	    $user = auth()->user();

	    $canCheckin = $user && (
		Gate::allows('admin') ||
		Gate::allows('superadmin') ||
		(method_exists($user, 'isSuperUser') && $user->isSuperUser()) ||
		$user->groups()->whereIn('name', $this->warehouseGroupNames())->exists()
	    );

	    if (!$canCheckin) {
		abort(403);
	    }

	    $ids = $request->input('selected_returns', []);

	    if (!is_array($ids) || count($ids) === 0) {
		return back()->with('error', 'Please select at least one return to check in.');
	    }

	    $returns = ReturnRequest::with('asset')
		->whereIn('id', $ids)
		->whereNull('canceled_at')
		->whereNull('closed_at')
		->whereNotNull('received_at')
		->whereNull('checked_in_at')
		->get();

	    if ($returns->isEmpty()) {
		return back()->with('error', 'No valid returns found for check in.');
	    }

	    $inArchiveId = \App\Models\Statuslabel::where('name', 'In Archive')->value('id');
	    $archiveLocationId = \App\Models\Location::where('name', 'Archive')->value('id');

	    $count = 0;

	    foreach ($returns as $return) {
		$asset = $return->asset;

		if (!$asset) {
		    continue;
		}

		$target = $asset->assignedTo;
		$originalValues = $asset->getRawOriginal();
		$checkinAt = now();

		$asset->expected_checkin = null;
		$asset->assignedTo()->disassociate($asset);
		$asset->accepted = null;

		if ($inArchiveId) {
		    $asset->status_id = $inArchiveId;
		}

		if ($archiveLocationId) {
		    $asset->location_id = $archiveLocationId;
		    $asset->rtd_location_id = $archiveLocationId;
		}

		$asset->last_checkin = $checkinAt;

		if ($asset->save()) {
		    $return->checked_in_at = $checkinAt;
		    $return->closed_at = $checkinAt;
		    $return->save();

		    if ($target) {
		        event(new \App\Events\CheckoutableCheckedIn(
		            $asset,
		            $target,
		            $user,
		            'Bulk checkin from returns',
		            $checkinAt,
		            $originalValues
		        ));
		    }

		    $count++;
		}
	    }

	    if ($count === 0) {
		return back()->with('error', 'No returns were checked in.');
	    }

	    return back()->with('success', $count . ' returns checked in successfully.');
	}
}
