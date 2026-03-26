<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\ReturnRequest;
use App\Notifications\ReturnStatusNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

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

	    ReturnRequest::query()
		->whereNull('canceled_at')
		->whereNull('closed_at')
		->whereHas('asset', function ($q) {
		    $q->whereNull('assigned_to');
		})
		->update([
		    'checked_in_at' => now(),
		    'closed_at'     => now(),
		]);

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

        $exists = ReturnRequest::where('asset_id', $asset->id)
            ->whereNull('canceled_at')
            ->whereNull('closed_at')
            ->exists();

        if ($exists) {
            return back()->with('success', 'Return request already exists.');
        }

        $return = ReturnRequest::create([
            'asset_id'     => $asset->id,
            'requested_by' => $user?->id,
            'requested_at' => now(),
        ]);

        $recipients = $this->warehouseRecipients();
        Notification::send($recipients, new ReturnStatusNotification('requested', $return, $asset, $user));

        return back()->with('success', 'Return request sent to Warehouse.');
    }

    public function markInTransit(ReturnRequest $return)
    {
        $user = auth()->user();

        $isSecretary = $user && $user->groups()
            ->whereIn('name', $this->secretaryGroupNames())
            ->exists();

        if (!$isSecretary) {
            abort(403);
        }

        if ($return->canceled_at || $return->received_at || $return->closed_at || $return->checked_in_at) {
            return back()->with('error', 'Return is closed.');
        }

        if (!$return->in_transit_at) {
            $return->in_transit_at = now();
            $return->save();

            $recipients = $this->warehouseRecipients();
            Notification::send($recipients, new ReturnStatusNotification('in_transit', $return, $return->asset, $user));
        }

        return back()->with('success', 'Marked as in transit.');
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

	    ReturnRequest::query()
		->whereNull('canceled_at')
		->whereNull('closed_at')
		->whereHas('asset', function ($q) {
		    $q->whereNull('assigned_to');
		})
		->update([
		    'checked_in_at' => now(),
		    'closed_at'     => now(),
		]);

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
}
