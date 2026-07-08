<?php

namespace App\Http\Controllers\Account;

use App\Events\CheckoutAccepted;
use App\Events\CheckoutDeclined;
use App\Events\ItemAccepted;
use App\Events\ItemDeclined;
use App\Http\Controllers\Controller;
use App\Mail\CheckoutAcceptanceResponseMail;
use App\Models\CheckoutAcceptance;
use App\Models\Company;
use App\Models\Contracts\Acceptable;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AcceptanceItemAcceptedNotification;
use App\Notifications\AcceptanceItemAcceptedToUserNotification;
use App\Notifications\AcceptanceItemDeclinedNotification;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use \Illuminate\Contracts\View\View;
use \Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use App\Helpers\Helper;
use App\Models\Statuslabel;
use Illuminate\Support\Facades\Auth;
use App\Notifications\AssetDeclinedForRecheckoutNotification;

class AcceptanceController extends Controller
{
    /**
     * Show a listing of pending checkout acceptances for the current user
     */
    public function index() : View
	{
	    $user = auth()->user();

	    $myAcceptances = CheckoutAcceptance::pending()
    ->where('assigned_to_id', $user->id)
    ->whereHasMorph('checkoutable', [\App\Models\Asset::class], function ($query) use ($user) {
        $query->where('assigned_type', \App\Models\User::class)
              ->where('assigned_to', $user->id);
    })
    ->get();

	    $departmentAcceptances = CheckoutAcceptance::pending()
		->where('assigned_to_id', '!=', $user->id)
		->whereHasMorph('checkoutable', [\App\Models\Asset::class], function ($query) use ($user) {
		    $query->where('location_id', $user->location_id);
		})
		->get();

	    return view('account/accept.index', compact('myAcceptances', 'departmentAcceptances'));
	}

    /**
     * Shows a form to either accept or decline the checkout acceptance
     *
     * @param  int  $id
     */
    public function create($id) : View | RedirectResponse
    {
        $acceptance = CheckoutAcceptance::find($id);


        if (is_null($acceptance)) {
            return redirect()->route('account.accept')->with('error', trans('admin/hardware/message.does_not_exist'));
        }

        if (! $acceptance->isPending()) {
            return redirect()->route('account.accept')->with('error', trans('admin/users/message.error.asset_already_accepted'));
        }

        if (
	    $acceptance->checkoutable_type === \App\Models\Asset::class &&
	    $acceptance->checkoutable &&
	    $acceptance->checkoutable->location_id !== auth()->user()->location_id
	) {
	    return redirect()->route('account.accept')->with('error', trans('admin/users/message.error.incorrect_user_accepted'));
	}

        if (! Company::isCurrentUserHasAccess($acceptance->checkoutable)) {
            return redirect()->route('account.accept')->with('error', trans('general.error_user_company'));
        }

        return view('account/accept.create', compact('acceptance'));
    }

    /**
     * Stores the accept/decline of the checkout acceptance
     *
     * @param  Request $request
     * @param  int  $id
     */
    public function store(Request $request, $id) : RedirectResponse
    {

        if (!$acceptance = CheckoutAcceptance::find($id)) {
            return redirect()->route('account.accept')->with('error', trans('admin/hardware/message.does_not_exist'));
        }
        
        $assigned_user = User::find($acceptance->assigned_to_id);
        $settings = Setting::getSettings();
        $sig_filename='';


        if (! $acceptance->isPending()) {
            return redirect()->route('account.accept')->with('error', trans('admin/users/message.error.asset_already_accepted'));
        }

        if (
	    $acceptance->checkoutable_type === \App\Models\Asset::class &&
	    $acceptance->checkoutable &&
	    $acceptance->checkoutable->location_id !== auth()->user()->location_id
	) {
	    return redirect()->route('account.accept')->with('error', trans('admin/users/message.error.incorrect_user_accepted'));
	}

        if (! Company::isCurrentUserHasAccess($acceptance->checkoutable)) {
            return redirect()->route('account.accept')->with('error', trans('general.insufficient_permissions'));
        }

        if (! $request->filled('asset_acceptance')) {
            return redirect()->back()->with('error', trans('admin/users/message.error.accept_or_decline'));
        }

        /**
         * Check for the signature directory
         */
        if (! Storage::exists('private_uploads/signatures')) {
            Storage::makeDirectory('private_uploads/signatures', 775);
        }

        /**
         * Check for the eula-pdfs directory
         */
        if (! Storage::exists('private_uploads/eula-pdfs')) {
            Storage::makeDirectory('private_uploads/eula-pdfs', 775);
        }

        $item = $acceptance->checkoutable;
        
        if ($item instanceof \App\Models\Asset) {
	    $hasOpenReturn = \App\Models\ReturnRequest::where('asset_id', $item->id)
		->whereNull('canceled_at')
		->whereNull('closed_at')
		->exists();

	    if ($hasOpenReturn) {
		return redirect()->route('account.accept')
		    ->with('error', 'This asset has an open return request.');
	    }
	}

        // If signatures are required, make sure we have one
        if (Setting::getSettings()->require_accept_signature == '1') {

            // The item was accepted, check for a signature
            if ($request->filled('signature_output')) {
                $sig_filename = 'siglog-' . Str::uuid() . '-' . date('Y-m-d-his') . '.png';
                $data_uri = $request->input('signature_output');
                $encoded_image = explode(',', $data_uri);
                $decoded_image = base64_decode($encoded_image[1]);
                Storage::put('private_uploads/signatures/' . $sig_filename, (string)$decoded_image);

                // No image data is present, kick them back.
                // This mostly only applies to users on super-duper crapola browsers *cough* IE *cough*
            } else {
                return redirect()->back()->with('error', trans('general.shitty_browser'));
            }
        }


        // Convert PDF logo to base64 for TCPDF
        // This is needed for TCPDF to properly embed the image if it's a png and the cache isn't writable
        $encoded_logo = null;
        if (($settings->acceptance_pdf_logo) && (Storage::disk('public')->exists($settings->acceptance_pdf_logo))) {
            $encoded_logo = base64_encode(file_get_contents(public_path() . '/uploads/' . $settings->acceptance_pdf_logo));
        }

        // Get the data array ready for the notifications and PDF generation
        $data = [
            'item_tag' => $item->asset_tag,
            'item_name' => $item->display_name, // this handles licenses seats, which don't have a 'name' field
            'item_model' => $item->model?->name,
            'item_serial' => $item->serial,
            'item_status' => $item->assetstatus?->name,
            'eula' => $item->getEula(),
            'note' => $request->input('note'),
            'check_out_date' => Helper::getFormattedDateObject($acceptance->created_at, 'datetime', false),
            'accepted_date' => Helper::getFormattedDateObject(now()->format('Y-m-d H:i:s'), 'datetime', false),
            'declined_date' => Helper::getFormattedDateObject(now()->format('Y-m-d H:i:s'), 'datetime', false),
            'assigned_to' => $assigned_user->display_name,
            'email' => $assigned_user->email,
            'employee_num' => $assigned_user->employee_num,
            'site_name' => $settings->site_name,
            'company_name' => $item->company?->name?? $settings->site_name,
            'signature' => (($sig_filename && array_key_exists('1', $encoded_image))) ? $encoded_image[1] : null,
            'logo' => ($encoded_logo) ?? null,
            'date_settings' => $settings->date_display_format,
            'qty' => $acceptance->qty ?? 1,
        ];

        if ($request->input('asset_acceptance') == 'accepted') {
        
        	if ($acceptance->checkoutable_type === \App\Models\Asset::class) {
		    $withDeptId = Statuslabel::where('name', 'With Department')->value('id');

		    if ($withDeptId) {
			$item->status_id = $withDeptId;
		    }

		    if (auth()->user()->location_id) {
			$item->location_id = auth()->user()->location_id;
		    }

		    $item->save();
		}
	
	    $pdf_filename = null;
/*
            $pdf_filename = 'accepted-'.$acceptance->checkoutable_id.'-'.$acceptance->display_checkoutable_type.'-eula-'.date('Y-m-d-h-i-s').'.pdf';

            // Generate the PDF content
            $pdf_content = $acceptance->generateAcceptancePdf($data, $acceptance);
            Storage::put('private_uploads/eula-pdfs/' .$pdf_filename, $pdf_content);

*/
            // Log the acceptance
            $acceptance->accept($sig_filename, $item->getEula(), $pdf_filename, $request->input('note'));

/*
            // Send the PDF to the signing user
            if (($request->input('send_copy') == '1') && ($assigned_user->email !='')) {

                // Add the attachment for the signing user into the $data array
                $data['file'] = $pdf_filename;
                try {
                    $assigned_user->notify((new AcceptanceItemAcceptedToUserNotification($data))->locale($assigned_user->locale));
                } catch (\Exception $e) {
                    Log::warning($e);
                }
            }
            try {
                $acceptance->notify((new AcceptanceItemAcceptedNotification($data))->locale(Setting::getSettings()->locale));
            } catch (\Exception $e) {
                Log::warning($e);
            }
*/
            event(new CheckoutAccepted($acceptance));

            $return_msg = trans('admin/users/message.accepted');

        // Item was declined
        } else {
        	for ($i = 0; $i < ($acceptance->qty ?? 1); $i++) {
			$acceptance->decline($sig_filename, $request->input('note'));
		    }
		    
		    if ($acceptance->checkoutable_type === \App\Models\Asset::class) {
		    $previousStatusId = null;

		    if (!empty($item->notes) && preg_match('/PREVIOUS_STATUS_ID:(\d+)/', $item->notes, $matches)) {
			$previousStatusId = $matches[1] ?? null;
		    }

		    if (!empty($previousStatusId)) {
			$item->status_id = (int) $previousStatusId;
		    } else {
			$returnedToDeptId = Statuslabel::where('name', 'Returned to Department')->value('id');
			if ($returnedToDeptId) {
			    $item->status_id = $returnedToDeptId;
			}
		    }

		    if (!empty($item->rtd_location_id)) {
			$item->location_id = $item->rtd_location_id;
		    }

		    if (!empty($item->notes)) {
			$item->notes = preg_replace('/\n?PREVIOUS_STATUS_ID:\d+/', '', $item->notes);
			$item->notes = trim($item->notes);
		    }

		    $item->save();

		    $sender = null;
		    $lastCheckoutLog = $item->assetlog()
			->where('action_type', 'checkout')
			->latest('id')
			->first();

		    if ($lastCheckoutLog && !empty($lastCheckoutLog->created_by)) {
			$sender = User::find($lastCheckoutLog->created_by);
		    }

		    if ($sender) {
			$sender->unreadNotifications()
			    ->where('data->type', 'asset_declined_recheckout')
			    ->where('data->asset_id', $item->id)
			    ->update(['read_at' => now()]);

			$sender->unreadNotifications()
			    ->where('data->type', 'asset_request')
			    ->where('data->item_id', $item->id)
			    ->update(['read_at' => now()]);

			$sender->notify(new AssetDeclinedForRecheckoutNotification([
			    'asset_id' => $item->id,
			    'asset_tag' => $item->asset_tag,
			    'asset_name' => $item->name ?? $item->display_name,
			    'declined_by' => auth()->user()?->display_name,
			    'note' => $request->input('note'),
			    'message' => 'The user declined receiving this file. Please checkout it again.',
			]));
		    }
		}

		    /*
		    $acceptance->notify(new AcceptanceItemDeclinedNotification($data));
		    Log::debug('New event acceptance.');
		    event(new CheckoutDeclined($acceptance));
		    */

		    $return_msg = trans('admin/users/message.declined');
        }

/*
        // Send an email notification if one is requested
        if ($acceptance->alert_on_response_id) {
            try {
                $recipient = User::find($acceptance->alert_on_response_id);

                if ($recipient?->email) {
                    Log::debug('Attempting to send email acceptance.');
                    Mail::to($recipient)->send(new CheckoutAcceptanceResponseMail(
                        $acceptance,
                        $recipient,
                        $request->input('asset_acceptance') === 'accepted',
                    ));
                    Log::debug('Send email notification sucess on checkout acceptance response.');
                }
            } catch (Exception $e) {
                Log::error($e->getMessage());
                Log::warning($e);
            }
        }
  
*/
        if (Auth::check() && isset($item)) {
	    Auth::user()->unreadNotifications()
		->where('data->type', 'acceptance_required')
		->where(function ($q) use ($item) {
		    $q->where('data->item_id', $item->id);

		    if (!empty($item->asset_tag)) {
		        $q->orWhere('data->item_tag', $item->asset_tag);
		    }
		})
		->update(['read_at' => now()]);
	}
        return redirect()->to('account/accept')->with('success', $return_msg);

    }
    
    
    public function bulkAccept(Request $request) : RedirectResponse
{
    $ids = $request->input('selected_acceptances', []);

    if (!is_array($ids) || count($ids) === 0) {
        return redirect()->route('account.accept')->with('error', 'No items selected.');
    }

    if (Setting::getSettings()->require_accept_signature == '1') {
        return redirect()->route('account.accept')->with('error', 'Bulk accept is not available while signature is required.');
    }

    $user = auth()->user();

    $acceptances = CheckoutAcceptance::pending()
        ->whereIn('id', $ids)
        ->whereHasMorph('checkoutable', [\App\Models\Asset::class], function ($query) use ($user) {
            $query->where('location_id', $user->location_id);
        })
        ->get();

    if ($acceptances->isEmpty()) {
        return redirect()->route('account.accept')->with('error', 'No valid acceptances found.');
    }

    if ($acceptances->count() !== count($ids)) {
        return redirect()->route('account.accept')->with('error', 'Some selected items are invalid.');
    }

    $errors = [];

    foreach ($acceptances as $acceptance) {
        if (! $acceptance->isPending()) {
            $errors[] = 'Item already processed.';
            continue;
        }

        if (
            $acceptance->checkoutable_type === \App\Models\Asset::class &&
            $acceptance->checkoutable &&
            $acceptance->checkoutable->location_id !== $user->location_id
        ) {
            $errors[] = 'One item belongs to another location.';
            continue;
        }

        if (! Company::isCurrentUserHasAccess($acceptance->checkoutable)) {
            $errors[] = 'Insufficient permissions for one selected item.';
            continue;
        }

        $item = $acceptance->checkoutable;

        if (!$item) {
            $errors[] = 'Missing item.';
            continue;
        }
        
        $hasOpenReturn = \App\Models\ReturnRequest::where('asset_id', $item->id)
	    ->whereNull('canceled_at')
	    ->whereNull('closed_at')
	    ->exists();

	if ($hasOpenReturn) {
	    $errors[] = 'Asset ' . $item->asset_tag . ' has an open return request.';
	    continue;
	}

        try {
            if ($acceptance->checkoutable_type === \App\Models\Asset::class) {
                $withDeptId = Statuslabel::where('name', 'With Department')->value('id');

                if ($withDeptId) {
                    $item->status_id = $withDeptId;
                }

                if ($user->location_id) {
                    $item->location_id = $user->location_id;
                }

                $item->save();
            }

            $acceptance->accept('', $item->getEula(), null, 'Bulk accepted');
            event(new CheckoutAccepted($acceptance));

            $user->unreadNotifications()
                ->where('data->type', 'acceptance_required')
                ->where(function ($q) use ($item) {
                    $q->where('data->item_id', $item->id);

                    if (!empty($item->asset_tag)) {
                        $q->orWhere('data->item_tag', $item->asset_tag);
                    }
                })
                ->update(['read_at' => now()]);

        } catch (\Throwable $e) {
            report($e);
            $errors[] = 'Failed to accept item ID '.$acceptance->id;
        }
    }

    if (!empty($errors)) {
        return redirect()->route('account.accept')->with('error', implode(' | ', $errors));
    }

    return redirect()->route('account.accept')->with('success', 'Selected items accepted successfully.');
}


}
