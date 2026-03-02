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
use Illuminate\Http\Request;
use App\Models\CheckoutRequest;
use App\Notifications\AcceptanceRequiredNotification;
use Illuminate\Support\Facades\Schema;

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

	public function create(Asset $asset, Request $request) : View | RedirectResponse
	{
		$this->authorize('checkout', $asset);

		if (!$asset->model) {
			return redirect()->route('hardware.show', $asset)
				->with('error', trans('admin/hardware/general.model_invalid_fix'));
		}

		// Validation κανόνες
		$asset->setRules($asset->getRules() + $asset->customFieldValidationRules());

		if ($asset->isInvalid()) {
			return redirect()->route('hardware.edit', $asset)->withErrors($asset->getErrors());
		}

		
		 session()->put('checkout_to_type', 'user');
		 
		if ($request->has('requested_location') || $request->has('requested_user') || $request->has('requested_status')) {

			// Παίρνουμε ότι υπάρχει ήδη στο _old_input (αν υπάρχει)
			$oldInput = session()->get('_old_input', []);

			// Prefill USER (Assigned to user) από το request
			if ($request->filled('requested_user')) {
				$oldInput['assigned_user'] = $request->query('requested_user');
			}

			// Αν θέλεις να κρατάς και το location, το αφήνουμε κι αυτό:
			if ($request->filled('requested_location')) {
				$oldInput['assigned_location'] = $request->query('requested_location');
			}

			// Σπρώχνουμε τα old values πίσω στο session
			session()->flash('_old_input', $oldInput);

		}
		
		// Default status στο checkout: In Transit (μόνο αν δεν υπάρχει ήδη old input)
		if (!session()->hasOldInput('status_id')) {
			$inTransitId = \App\Models\Statuslabel::where('name', 'In Transit')->value('id');
			if ($inTransitId) {
				$asset->status_id = $inTransitId;
			}
		}

		// ✅ Allow TRANSFER checkout screen even if asset is already checked out
		$isTransfer = $request->filled('requested_user');

		if ($isTransfer) {
			return view('hardware/checkout', compact('asset'))
				->with('statusLabel_list', Helper::deployableStatusLabelList())
				->with('table_name', 'Assets')
				->with('item', $asset);
		}


		// Αν είναι διαθέσιμο για checkout, δείξε τη φόρμα
		if ($asset->availableForCheckout()) {
			return view('hardware/checkout', compact('asset'))
				->with('statusLabel_list', Helper::deployableStatusLabelList())
				->with('table_name', 'Assets')
				->with('item', $asset);
		}

		// Διαφορετικά, μήνυμα λάθους
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


        try {
            
			if (! $asset = Asset::find($assetId)) {
				return redirect()->route('hardware.index')
					->with('error', trans('admin/hardware/message.does_not_exist'));
			}

			/** ✅ ADD THIS BLOCK */
			$transfer = (bool) $request->input('transfer', false);
			$targetId = (int) $request->input('assigned_user');

			if ($transfer && $targetId) {
				$alreadyToSameUser =
					($asset->assigned_type === \App\Models\User::class)
					&& ((int)$asset->assigned_to === $targetId);

				if ($alreadyToSameUser) {
					return redirect()->back()
						->with('success', trans('admin/hardware/message.checkout.success'));
				}
			}

			if ($transfer && ! $asset->availableForCheckout()) {

				$me = auth()->user();

				$isPrivileged = $me && $me->groups()
					->whereIn('name', ['Admin', 'Warehouse', 'Archivist', 'Archivists'])
					->exists();

				$isHolder = $me && ((int) $asset->assigned_to === (int) $me->id);

				if (! $isPrivileged && ! $isHolder) {
					return redirect()->back()->with('error', 'You cannot transfer this asset.');
				}

				$asset->expected_checkin = null;

				if (method_exists($asset, 'assignedTo')) {
					$asset->assignedTo()->disassociate();
				} else {
					$asset->assigned_to = null;
					$asset->assigned_type = null;
				}

				$asset->accepted = null;

				\App\Models\CheckoutAcceptance::pending()
					->where('checkoutable_type', \App\Models\Asset::class)
					->where('checkoutable_id', $asset->id)
					->delete();

				$asset->save();
			}

			if (! $asset->availableForCheckout()) {
				return redirect()->route('hardware.index')
					->with('error', trans('admin/hardware/message.checkout.not_available'));
			}

			$this->authorize('checkout', $asset);


            if (!$asset->model) {
                return redirect()->route('hardware.show', $asset)->with('error', trans('admin/hardware/general.model_invalid_fix'));
            }

            $admin = auth()->user();
			
			// Force always checkout to USER
			$request->merge(['checkout_to_type' => 'user']);
			session()->put('checkout_to_type', 'user');

			// Get target user directly
			$targetId = $request->input('assigned_user');
			$target = \App\Models\User::find($targetId);

			if (!$target) {
				return redirect()->back()->with('error', 'Please select a user to check out to.');
			}

            $checkout_at = date('Y-m-d H:i:s');
            if (($request->filled('checkout_at')) && ($request->get('checkout_at') != date('Y-m-d'))) {
                $checkout_at = $request->get('checkout_at');
            }

            $expected_checkin = '';
            if ($request->filled('expected_checkin')) {
                $expected_checkin = $request->get('expected_checkin');
            }

            if ($request->filled('status_id')) {
                $asset->status_id = $request->get('status_id');
            }


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

            session()->put(['redirect_option' => $request->get('redirect_option'), 'checkout_to_type' => $request->get('checkout_to_type')]);


			if ($asset->checkOut($target, $admin, $checkout_at, $expected_checkin, $request->get('note'), $request->get('name'))) {

				// ✅ Πιάσε ΜΟΝΟ το πιο πρόσφατο open request για αυτό το asset
				$latest = CheckoutRequest::where('requestable_type', \App\Models\Asset::class)
					->where('requestable_id', $asset->id)
					->whereNull('canceled_at')
					->when(Schema::hasColumn('checkout_requests', 'fulfilled_at'), fn($q) => $q->whereNull('fulfilled_at'))
					->orderByDesc('created_at')
					->orderByDesc('id')
					->first();

				if ($latest) {
					$updates = [];

					if (Schema::hasColumn('checkout_requests', 'checked_out_at')) {
						$updates['checked_out_at'] = now();
					}

					if (Schema::hasColumn('checkout_requests', 'fulfilled_at')) {
						$updates['fulfilled_at'] = now();
					}

					if (!empty($updates)) {
						$latest->update($updates);
					}

					// ✅ Ακύρωσε ΟΛΑ τα παλιότερα open requests για το ίδιο asset
					CheckoutRequest::where('requestable_type', \App\Models\Asset::class)
						->where('requestable_id', $asset->id)
						->whereNull('canceled_at')
						->where('id', '!=', $latest->id)
						->when(Schema::hasColumn('checkout_requests', 'fulfilled_at'), fn($q) => $q->whereNull('fulfilled_at'))
						->update(['canceled_at' => now()]);
				}

				// ✅ Κράτα ΜΟΝΟ 1 Acceptance notification (το “πλούσιο”)
				if (method_exists($asset, 'requireAcceptance') && $asset->requireAcceptance() && $target) {

					// 1) Σβήσε το default acceptance notification που πιθανότατα έφτιαξε το checkOut()
					$target->unreadNotifications()
						->where('data->type', 'acceptance_required')
						->where('data->item_tag', $asset->asset_tag)
						->delete();

					// 2) Στείλε το δικό σου “πλούσιο”
					$target->notify(new \App\Notifications\AcceptanceApprovalRequiredNotification([
						'item_tag'   => $asset->asset_tag,
						'item_name'  => $asset->name,
						'from_name'  => optional(auth()->user())->name,
						'url'        => url('/account/accept'),
					]));
				}

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
