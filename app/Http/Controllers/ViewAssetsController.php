<?php

namespace App\Http\Controllers;

use App\Actions\CheckoutRequests\CancelCheckoutRequestAction;
use App\Actions\CheckoutRequests\CreateCheckoutRequestAction;
use App\Enums\ActionType;
use App\Exceptions\AssetNotRequestable;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\RequestAssetCancelation;
use App\Notifications\RequestAssetNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use \Illuminate\Contracts\View\View;
use Exception;
use Illuminate\Support\Facades\Notification;

use App\Models\ReturnRequest;
use App\Notifications\ReturnStatusNotification;
use App\Models\CheckoutAcceptance;


/**
 * This controller handles all actions related to the ability for users
 * to view their own assets in the Snipe-IT Asset Management application.
 *
 * @version    v1.0
 */
class ViewAssetsController extends Controller
{
    /**
     * Extract custom fields that should be displayed in user view.
     *
     * @param User $user
     * @return array
     */
    private function extractCustomFields(User $user): array
    {
        $fieldArray = [];
        foreach ($user->assets as $asset) {
            if ($asset->model && $asset->model->fieldset) {
                foreach ($asset->model->fieldset->fields as $field) {
                    if ($field->display_in_user_view == '1') {
                        $fieldArray[$field->db_column] = $field->name;
                    }
                }
            }
        }
        return array_unique($fieldArray);
    }

    /**
     * Get list of users viewable by the current user.
     *
     * @param User $authUser
     * @return \Illuminate\Support\Collection
     */
    private function getViewableUsers(User $authUser): \Illuminate\Support\Collection
    {
        // SuperAdmin sees all users
        if ($authUser->isSuperUser()) {
            return User::select('id', 'first_name', 'last_name', 'username')
                ->where('activated', 1)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();
        }

        // Regular manager sees only their subordinates + self
        $managedUsers = $authUser->getAllSubordinates();
        
        // If user has subordinates, show them with self at beginning
        if ($managedUsers->count() > 0) {
            return collect([$authUser])->merge($managedUsers)
                ->sortBy('last_name')
                ->sortBy('first_name');
        }
        
        // User has no subordinates, only sees themselves
        return collect([$authUser]);
    }

    /**
     * Get the selected user ID from request or default to current user.
     *
     * @param Request $request
     * @param \Illuminate\Support\Collection $subordinates
     * @param int $defaultUserId
     * @return int
     */
    private function getSelectedUserId(Request $request, \Illuminate\Support\Collection $subordinates, int $defaultUserId): int
    {
        // If no subordinates or no user_id in request, return default
        if ($subordinates->count() <= 1 || !$request->filled('user_id')) {
            return $defaultUserId;
        }

        $requestedUserId = (int) $request->input('user_id');
        
        // Validate if the requested user is allowed
        if ($subordinates->contains('id', $requestedUserId)) {
            return $requestedUserId;
        }
        
        // If invalid ID or not authorized, return default
        return $defaultUserId;
    }

    /**
     * Show user's assigned assets with optional manager view functionality.
     *
     */
    public function getIndex(Request $request) : View | RedirectResponse
    {
        $authUser = auth()->user();
        $settings = Setting::getSettings();
        $subordinates = collect();
        $selectedUserId = $authUser->id;

        // Process manager view if enabled
        if ($settings->manager_view_enabled) {
            $subordinates = $this->getViewableUsers($authUser);
            $selectedUserId = $this->getSelectedUserId($request, $subordinates, $authUser->id);
        }

        // Load the data for the user to be viewed (either auth user or selected subordinate)
        $userToView = User::with([
            'assets',
            'assets.model',
            'assets.model.fieldset.fields',
            'consumables',
            'accessories',
            'licenses'
        ])->find($selectedUserId);

        // If the user to view couldn't be found (shouldn't happen with proper logic), redirect with error
        if (!$userToView) {
            return redirect()->route('view-assets')->with('error', trans('admin/users/message.user_not_found'));
        }

        // Process custom fields for the user being viewed
        $fieldArray = $this->extractCustomFields($userToView);
	
	$pendingAcceptanceAssetIds = CheckoutAcceptance::query()
	    ->where('checkoutable_type', Asset::class)
	    ->where('assigned_to_id', $userToView->id)
	    ->whereNull('accepted_at')
	    ->whereNull('declined_at')
	    ->pluck('checkoutable_id')
	    ->map(fn ($id) => (int) $id)
	    ->all();
	 
	 $visibleAssetsCount = $userToView->assets
	    ->whereNotIn('id', $pendingAcceptanceAssetIds)
	    ->count();
	    
        // Pass the necessary data to the view
        return view('account/view-assets', [
	    'user' => $userToView,
	    'field_array' => $fieldArray,
	    'settings' => $settings,
	    'subordinates' => $subordinates,
	    'selectedUserId' => $selectedUserId,
	    'pendingAcceptanceAssetIds' => $pendingAcceptanceAssetIds,
	    'visibleAssetsCount' => $visibleAssetsCount,
	]);
    }

    /**
     * Returns view of requestable items for a user.
     */
    public function getRequestableIndex() : View
    {
        $assets = Asset::with('model', 'defaultLoc', 'location', 'assignedTo', 'requests')->Hardware()->RequestableAssets();
        $models = AssetModel::with([
            'category',
            'requests',
            'assets' => function ($q) {
                $q->where('requestable', 1)
                    ->whereHas('assetstatus', fn ($s) =>
                    $s->where('archived', 0)
                        ->where(fn ($s) =>
                        $s->where('deployable', 1)->orWhere('pending', 1)
                        )
                    );
            },
        ])->RequestableModels()->get();

        return view('account/requestable-assets', compact('assets', 'models'));
    }

    public function getRequestItem(Request $request, $itemType, $itemId = null, $cancel_by_admin = false, $requestingUser = null): RedirectResponse
    {
        $item = null;
        $fullItemType = 'App\\Models\\'.studly_case($itemType);

        if ($itemType == 'asset_model') {
            $itemType = 'model';
        }
        $item = call_user_func([$fullItemType, 'find'], $itemId);

        $user = auth()->user();

        $logaction = new Actionlog();
        $logaction->item_id = $data['asset_id'] = $item->id;
        $logaction->item_type = $fullItemType;
        $logaction->created_at = $data['requested_date'] = date('Y-m-d H:i:s');

        if ($user->location_id) {
            $logaction->location_id = $user->location_id;
        }

        $logaction->target_id = $data['user_id'] = auth()->id();
        $logaction->target_type = User::class;

        $data['item_quantity'] = $request->has('request-quantity') ? e($request->input('request-quantity')) : 1;
        $data['requested_by'] = $user->display_name;
        $data['item'] = $item;
        $data['item_type'] = $itemType;
        $data['target'] = auth()->user();

        if ($fullItemType == Asset::class) {
            $data['item_url'] = route('hardware.show', $item->id);
        } else {
            $data['item_url'] = route("view/{$itemType}", $item->id);
        }

        $settings = Setting::getSettings();

        if (($item_request = $item->isRequestedBy($user)) || $cancel_by_admin) {
            $item->cancelRequest($requestingUser);
            $data['item_quantity'] = ($item_request) ? $item_request->qty : 1;
            $logaction->logaction(ActionType::RequestCanceled);

            if (($settings->alert_email != '') && ($settings->alerts_enabled == '1') && (! config('app.lock_passwords'))) {
                $location = null;

		if ($fullItemType === \App\Models\Asset::class) {
		    $item->loadMissing('location');
		    $location = $item->location;
		} else {
		    $location = auth()->user()->location;
		}

		if ($location) {
		    $recipients = User::where('activated', 1)
			->where('location_id', $location->id)
			->where('id', '!=', auth()->id())
			->get();

		    \Illuminate\Support\Facades\Notification::send(
			$recipients,
			new RequestAssetCancelation($data)
		    );
		}
            }

            return redirect()->back()->with('success')->with('success', trans('admin/hardware/message.requests.canceled'));
        } else {
            $openRequest = $item->requests()
		    ->whereNull('canceled_at')
		    ->whereNull('fulfilled_at')
		    ->with('user')
		    ->latest()
		    ->first();

		if ($openRequest) {
		    $requestedBy = $openRequest->user?->display_name ?? 'another user';

		    return redirect()->back()->with(
			'error',
			'This file has already been requested by '.$requestedBy.'.'
		    );
		}
            $item->request();
            if (($settings->alert_email != '') && ($settings->alerts_enabled == '1') && (! config('app.lock_passwords'))) {
                $logaction->logaction('requested');
                $location = null;

		if ($fullItemType === \App\Models\Asset::class) {
		    $item->loadMissing('location');
		    $location = $item->location;
		} else {
		    $location = auth()->user()->location;
		}

		if ($location) {
		    $recipients = User::where('activated', 1)
			->where('location_id', $location->id)
			->where('id', '!=', auth()->id())
			->get();

		    \Illuminate\Support\Facades\Notification::send(
			$recipients,
			new RequestAssetNotification($data)
		    );
		}
            }

            return redirect()->route('requestable-assets')->with('success')->with('success', trans('admin/hardware/message.requests.success'));
        }
    }

    /**
     * Process a specific requested asset
     * @param null $assetId
     */
    public function store(Asset $asset): RedirectResponse
	{
	    $user = auth()->user();

	    if (!empty($user?->location_id) && (int) $asset->location_id === (int) $user->location_id) {
		return redirect()->back()->with('error', 'This asset is already in your location.');
	    }
	    
	    $hasOpenReturn = ReturnRequest::where('asset_id', $asset->id)
		    ->whereNull('canceled_at')
		    ->whereNull('closed_at')
		    ->exists();

		if ($hasOpenReturn) {
		    return redirect()->back()->with('error', 'This asset has an open return request.');
		}
	    $openRequest = $asset->requests()
		    ->whereNull('canceled_at')
		    ->whereNull('fulfilled_at')
		    ->with('user')
		    ->latest()
		    ->first();

		if ($openRequest) {
		    $requestedBy = $openRequest->user?->display_name ?? 'another user';

		    return redirect()->back()->with(
			'error',
			'This file has already been requested by '.$requestedBy.'.'
		    );
		}

	    try {
		CreateCheckoutRequestAction::run($asset, $user);
		return redirect()->route('requestable-assets')->with('success')->with('success', trans('admin/hardware/message.requests.success'));
	    } catch (AssetNotRequestable $e) {
		return redirect()->back()->with('error', 'Asset is not requestable');
	    } catch (AuthorizationException $e) {
		return redirect()->back()->with('error', trans('admin/hardware/message.requests.error'));
	    } catch (Exception $e) {
		report($e);
		return redirect()->back()->with('error', trans('general.something_went_wrong'));
	    }
	}
    
    public function bulkStore(Request $request): RedirectResponse
	{
	    $assetIds = $request->input('selected_assets', []);

	    if (!is_array($assetIds) || count($assetIds) === 0) {
		return redirect()->back()->with('error', 'Please select at least one asset.');
	    }

	    $assets = Asset::with('location')
		->whereIn('id', $assetIds)
		->get();

	    if ($assets->isEmpty()) {
		return redirect()->back()->with('error', 'No valid assets found.');
	    }

	    $locationIds = $assets->pluck('location_id')->filter()->unique()->values();

	    if ($locationIds->count() !== 1) {
		return redirect()->back()->with('error', 'All selected assets must have the same location.');
	    }

	    $settings = Setting::getSettings();
	    $location = $assets->first()->location;
	    $requester = auth()->user();
	    $requestedCount = 0;
	    $skippedCount = 0;
	    $skippedFiles = [];

	    foreach ($assets as $asset) {
		    if (!empty($requester?->location_id) && (int) $asset->location_id === (int) $requester->location_id) {
			    $skippedCount++;
			    $skippedFiles[] = $asset->asset_tag . ' - already in your location';
			    continue;
			}
		    
		    $hasOpenReturn = ReturnRequest::where('asset_id', $asset->id)
			    ->whereNull('canceled_at')
			    ->whereNull('closed_at')
			    ->exists();

			if ($hasOpenReturn) {
			    $skippedCount++;
			    $skippedFiles[] = $asset->asset_tag . ' - has an open return request';
			    continue;
			}
		    
		    $openRequest = $asset->requests()
			    ->whereNull('canceled_at')
			    ->whereNull('fulfilled_at')
			    ->with('user')
			    ->latest()
			    ->first();

			if ($openRequest) {
			    $skippedCount++;
			    $skippedFiles[] = $asset->asset_tag . ' - requested by ' . ($openRequest->user?->display_name ?? 'another user');
			    continue;
			}
			
		    try {
			CreateCheckoutRequestAction::run($asset, $requester, false);
			$requestedCount++;
		    } catch (\Exception $e) {
			    report($e);
			    $skippedCount++;
			    $skippedFiles[] = $asset->asset_tag . ' - ' . $e->getMessage();
			}
		}

	    if (
		$requestedCount > 0 &&
		($settings->alert_email != '') &&
		($settings->alerts_enabled == '1') &&
		(!config('app.lock_passwords')) &&
		$location
	    ) {
		$recipients = User::where('activated', 1)
		    ->where('location_id', $location->id)
		    ->where('id', '!=', $requester->id)
		    ->get();

		if ($recipients->isNotEmpty()) {
		    $data = [
			    'requester' => $requester,
			    'target' => $requester,
			    'item' => null,
			    'item_type' => 'bulk',
			    'item_quantity' => $requestedCount,
			    'note' => '',
			    'requested_date' => now(),
			    'title' => $requestedCount . ' new asset requests',
			    'message' => $requestedCount . ' new asset requests from ' . $requester->display_name,
			    'item_name' => $requestedCount . ' assets',
			];

		    Notification::send($recipients, new RequestAssetNotification($data));
		}
	    }

	    
	    if ($requestedCount === 0) {
		    $message = 'No requests were created. Skipped files: ' . implode(' | ', $skippedFiles);

		    return redirect()->back()->with('error', $message);
		    }

		$message = $requestedCount . ' requests created successfully.';

		if ($skippedCount > 0) {
		    $message .= ' ' . $skippedCount . ' files skipped: ' . implode(' | ', $skippedFiles);

		    return redirect()->route('requestable-assets')->with('warning', $message);
		}

		return redirect()->route('requestable-assets')->with('success', $message);
	    
	}
    
    public function destroy(Asset $asset): RedirectResponse
    {
        try {
            CancelCheckoutRequestAction::run($asset, auth()->user());
            return redirect()->route('requestable-assets')->with('success')->with('success', trans('admin/hardware/message.requests.canceled'));
        } catch (Exception $e) {
            report($e);
            return redirect()->back()->with('error', trans('general.something_went_wrong'));
        }
    }


    public function getRequestedAssets() : View
    {
        return view('account/requested');
    }
    
    private function warehouseRecipients()
	{
	    return \App\Models\User::query()
		->whereHas('groups', function ($g) {
		    $g->whereIn('name', ['Warehouse keeper']);
		})
		->get();
	}
    
    public function bulkReturnToArchive(Request $request): RedirectResponse
	{
	    $ids = $request->input('selected_assets', []);

	    if (!is_array($ids) || count($ids) === 0) {
		return redirect()->back()->with('error', 'Please select at least one asset.');
	    }

	    $assets = Asset::whereIn('id', $ids)->get();

	    if ($assets->isEmpty()) {
		return redirect()->back()->with('error', 'No valid assets found.');
	    }

	    $user = auth()->user();
	    $createdCount = 0;
	    $firstReturn = null;
	    $firstAsset = null;

	    foreach ($assets as $asset) {
		$hasPendingAcceptance = CheckoutAcceptance::query()
		    ->where('checkoutable_type', Asset::class)
		    ->where('checkoutable_id', $asset->id)
		    ->where('assigned_to_id', $asset->assigned_to)
		    ->whereNull('accepted_at')
		    ->whereNull('declined_at')
		    ->exists();

		if (
		    empty($asset->can_pickup) ||
		    $hasPendingAcceptance ||
		    !empty($asset->open_return_id)
		) {
		    continue;
		}

		$exists = ReturnRequest::where('asset_id', $asset->id)
		    ->whereNull('canceled_at')
		    ->whereNull('closed_at')
		    ->exists();

		if ($exists) {
		    continue;
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
		
		if (!$firstReturn) {
		    $firstReturn = $return;
		    $firstAsset = $asset;
		}

		$createdCount++;
	    }

	    if ($createdCount === 0) {
		return redirect()->back()->with('error', 'No return requests were created.');
	    }

	    $recipients = $this->warehouseRecipients();

	    if ($recipients->isNotEmpty() && $firstReturn && $firstAsset) {
		Notification::send($recipients, new ReturnStatusNotification(
		    'requested',
		    $firstReturn,
		    $firstAsset,
		    $user,
		    $createdCount
		));
	    }

	    return redirect()->back()->with('success', $createdCount . ' return requests created successfully.');
	}
	
	public function bulkCheckoutToDrg(Request $request): RedirectResponse
	{
	    $ids = $request->input('selected_assets', []);

	    if (!is_array($ids) || count($ids) === 0) {
		return redirect()->back()->with('error', 'Please select at least one asset.');
	    }

	    $drgUser = \App\Models\User::find(21);

	    if (!$drgUser) {
		return redirect()->back()->with('error', 'DRG user not found.');
	    }

	    $assets = Asset::whereIn('id', $ids)->get();

	    $checkedOutCount = 0;

	    foreach ($assets as $asset) {
	    
	    	    $hasPendingAcceptance = CheckoutAcceptance::query()
			    ->where('checkoutable_type', Asset::class)
			    ->where('checkoutable_id', $asset->id)
			    ->where('assigned_to_id', $asset->assigned_to)
			    ->whereNull('accepted_at')
			    ->whereNull('declined_at')
			    ->exists();

			if ($hasPendingAcceptance) {
			    continue;
			}

		    try {
			if (!empty($asset->open_return_id)) {
			    continue;
			}

		    $success = $asset->checkOut(
		        $drgUser,
		        auth()->user(),
		        date('Y-m-d H:i:s'),
		        null,
		        'Bulk checkout to DRG',
		        $asset->name
		    );

		    if ($success) {
		        $checkedOutCount++;
		    }
		} catch (\Throwable $e) {
		    report($e);
		}
	    }

	    return redirect()->back()->with('success', $checkedOutCount . ' assets checked out to DRG.');
	}
}
