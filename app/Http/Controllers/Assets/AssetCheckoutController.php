<?php

namespace App\Http\Controllers\Assets;

use App\Exceptions\CheckoutNotAllowed;
use App\Helpers\Helper;
use App\Http\Controllers\CheckInOutRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetCheckoutRequest;
use App\Models\Asset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Session;
use \Illuminate\Contracts\View\View;
use \Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;

class AssetCheckoutController extends Controller
{
    use CheckInOutRequest;

    /**
     * Returns a view that presents a form to check an asset out to a
     * user.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v1.0]
     * @return \Illuminate\Contracts\View\View
     */
    
public function create(Asset $asset) : View | RedirectResponse
{
    $this->authorize('checkout', $asset);

    if (!$asset->model) {
        return redirect()->route('hardware.show', $asset)
            ->with('error', trans('admin/hardware/general.model_invalid_fix'));
    }

    // Validation rules
    $asset->setRules($asset->getRules() + $asset->customFieldValidationRules());

    if ($asset->isInvalid()) {
        return redirect()->route('hardware.edit', $asset)
            ->withErrors($asset->getErrors());
    }
    
    $requestUserLocation = null;
    $requestedUserId = null;
    
    $req = \App\Models\CheckoutRequest::with('user')
	    ->where('requestable_type', \App\Models\Asset::class)
	    ->where('requestable_id', $asset->id)
	    ->whereNull('canceled_at')
	    ->latest()
	    ->first();

    if ($req && $req->user) {
	    $requestedUserId = $req->user->id;
	    $requestUserLocation = $req->user->location_id;
	}
    
    $inTransitId = \App\Models\Statuslabel::where('name', 'In Transit')->value('id');

    if ($inTransitId) {
        $asset->status_id = $inTransitId;
    }

    if ($asset->availableForCheckout() || $req) {
    
    	$requestUserLocation = null;

	$req = \App\Models\CheckoutRequest::with('user')
	    ->where('requestable_type', \App\Models\Asset::class)
	    ->where('requestable_id', $asset->id)
	    ->whereNull('canceled_at')
	    ->latest()
	    ->first();

	if ($req && $req->user) {
	    $requestUserLocation = $req->user->location_id;
	}
	
	$inTransitId = \App\Models\Statuslabel::where('name','In Transit')->value('id');

	if ($inTransitId) {
	    $asset->status_id = $inTransitId;
	}

        return view('hardware/checkout', compact('asset', 'requestUserLocation', 'requestedUserId'))
            ->with('statusLabel_list', Helper::deployableStatusLabelList())
            ->with('table_name', 'Assets')
            ->with('item', $asset);
    }

    return redirect()->route('hardware.index')
        ->with('error', trans('admin/hardware/message.checkout.not_available'));
}

    /**
     * Validate and process the form data to check out an asset to a user.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param AssetCheckoutRequest $request
     * @since [v1.0]
     */
    public function store(AssetCheckoutRequest $request, $assetId) : RedirectResponse
    {
    	
	$activeRequest = \App\Models\CheckoutRequest::with('user')
	    ->where('requestable_type', \App\Models\Asset::class)
	    ->where('requestable_id', $assetId)
	    ->whereNull('canceled_at')
	    ->latest()
	    ->first();
	
        try {
            // Check if the asset exists
            if (! $asset = Asset::find($assetId)) {
                return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.does_not_exist'));
            } elseif (! $asset->availableForCheckout() && ! $activeRequest) {
		return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.checkout.not_available'));
		}
            $this->authorize('checkout', $asset);

            if (!$asset->model) {
                return redirect()->route('hardware.show', $asset)->with('error', trans('admin/hardware/general.model_invalid_fix'));
            }

            $admin = auth()->user();
            
            // force checkout to USER
            $request->merge(['checkout_to_type' => 'user']);
            session()->put(['checkout_to_type' => 'user']);
            
            $target = \App\Models\User::find($request->input('assigned_user'));
            
            if (!$target) {
            	return redirect()->back()->with('error', 'Please select a user.');
            	}
        
            // location ΜΟΝΟ από τον user που έκανε request
            if ($target->location_id) {
		$asset->location_id = $target->location_id;
		}

            $checkout_at = date('Y-m-d H:i:s');
            if (($request->filled('checkout_at')) && ($request->input('checkout_at') != date('Y-m-d'))) {
                $checkout_at = $request->input('checkout_at');
            }

            $expected_checkin = '';
            if ($request->filled('expected_checkin')) {
                $expected_checkin = $request->input('expected_checkin');
            }

            $previousStatusId = $asset->status_id;

		$inTransitId = \App\Models\Statuslabel::where('name', 'In Transit')->value('id');
		if ($inTransitId) {
		    if (empty($asset->notes)) {
			$asset->notes = '';
		    }

		    $asset->notes = preg_replace('/\n?PREVIOUS_STATUS_ID:\d+/', '', $asset->notes);
		    $asset->notes = trim($asset->notes . "\nPREVIOUS_STATUS_ID:" . $previousStatusId);

		    $asset->status_id = $inTransitId;
		} elseif ($request->filled('status_id')) {
		    $asset->status_id = $request->input('status_id');
		}

		$asset->saveQuietly();
		$asset->refresh();

            if(!empty($asset->licenseseats->all())){
                if(request('checkout_to_type') == 'user') {
                    foreach ($asset->licenseseats as $seat){
                        $seat->assigned_to = $target->id;
                        $seat->save();
                    }
                }
            }

            // Add any custom fields that should be included in the checkout
            $asset->customFieldsForCheckinCheckout('display_checkout');

            $settings = \App\Models\Setting::getSettings();

            // We have to check whether $target->company_id is null here since locations don't have a company yet
            if (($settings->full_multiple_companies_support) && ((!is_null($target->company_id)) &&  (!is_null($asset->company_id)))) {
                if ($target->company_id != $asset->company_id){
                    return redirect()->route('hardware.checkout.create', $asset)->with('error', trans('general.error_user_company'));
                }
            }

            session()->put([
		    'redirect_option' => $request->input('redirect_option'),
		    'checkout_to_type' => 'user'
		]);
	
	    if (! $asset->availableForCheckout() && $activeRequest) {
		    $asset->assignedTo()->disassociate();
		    $asset->accepted = null;
		    $asset->expected_checkin = null;
		    $asset->save();
		}

            if ($asset->checkOut($target, $admin, $checkout_at, $expected_checkin, $request->input('note'), $request->input('name'))) {
            	
                return Helper::getRedirectOption($request, $asset->id, 'Assets')
                    ->with('success', trans('admin/hardware/message.checkout.success'));
            }
            // Redirect to the asset management page with error
            return redirect()->route("hardware.checkout.create", $asset)->with('error', trans('admin/hardware/message.checkout.error').$asset->getErrors());
        } catch (ModelNotFoundException $e) {
            return redirect()->back()->with('error', trans('admin/hardware/message.checkout.error'))->withErrors($asset->getErrors());
        } catch (CheckoutNotAllowed $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
