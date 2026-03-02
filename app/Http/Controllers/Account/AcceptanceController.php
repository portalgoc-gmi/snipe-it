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
use App\Notifications\AcceptanceAssetAcceptedNotification;
use App\Notifications\AcceptanceAssetAcceptedToUserNotification;
use App\Notifications\AcceptanceAssetDeclinedNotification;
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



class AcceptanceController extends Controller
{
    /**
     * Show a listing of pending checkout acceptances for the current user
     */
    public function index() : View
    {
        $acceptances = CheckoutAcceptance::forUser(auth()->user())->pending()->get();
        return view('account/accept.index', compact('acceptances'));
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

        if (! $acceptance->isCheckedOutTo(auth()->user())) {
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
		$acceptance = CheckoutAcceptance::find($id);

		if (is_null($acceptance)) {
			return redirect()->route('account.accept')->with('error', trans('admin/hardware/message.does_not_exist'));
		}

		$assigned_user = User::find($acceptance->assigned_to_id);
		$settings = Setting::getSettings();

		$sig_filename = '';
		$pdf_filename = '';      // ✅ FIX
		$encoded_image = [];     // ✅ FIX

		if (! $acceptance->isPending()) {
			return redirect()->route('account.accept')->with('error', trans('admin/users/message.error.asset_already_accepted'));
		}

		if (! $acceptance->isCheckedOutTo(auth()->user())) {
			return redirect()->route('account.accept')->with('error', trans('admin/users/message.error.incorrect_user_accepted'));
		}

		if (! Company::isCurrentUserHasAccess($acceptance->checkoutable)) {
			return redirect()->route('account.accept')->with('error', trans('general.insufficient_permissions'));
		}

		if (! $request->filled('asset_acceptance')) {
			return redirect()->back()->with('error', trans('admin/users/message.error.accept_or_decline'));
		}

		// signature directory
		if (! Storage::exists('private_uploads/signatures')) {
			Storage::makeDirectory('private_uploads/signatures', 775);
		}

		// eula-pdfs directory
		if (! Storage::exists('private_uploads/eula-pdfs')) {
			Storage::makeDirectory('private_uploads/eula-pdfs', 775);
		}

		$item = $acceptance->checkoutable_type::find($acceptance->checkoutable_id);

		// If signatures are required, make sure we have one
		if (Setting::getSettings()->require_accept_signature == '1') {
			if ($request->filled('signature_output')) {
				$sig_filename = 'siglog-' . Str::uuid() . '-' . date('Y-m-d-his') . '.png';
				$data_uri = $request->input('signature_output');
				$encoded_image = explode(',', $data_uri);
				$decoded_image = base64_decode($encoded_image[1]);
				Storage::put('private_uploads/signatures/' . $sig_filename, (string)$decoded_image);
			} else {
				return redirect()->back()->with('error', trans('general.shitty_browser'));
			}
		}

		// Convert PDF logo to base64 for TCPDF
		$encoded_logo = null;
		if ($settings->acceptance_pdf_logo) {
			$encoded_logo = base64_encode(file_get_contents(public_path() . '/uploads/' . $settings->acceptance_pdf_logo));
		}

		$data = [
			'item_tag' => $item->asset_tag,
			'item_name' => $item->name,
			'item_model' => $item->model?->name,
			'item_serial' => $item->serial,
			'item_status' => $item->assetstatus?->name,
			'eula' => $item->getEula(),
			'note' => $request->input('note'),
			'check_out_date' => Helper::getFormattedDateObject($acceptance->created_at, 'datetime', false),
			'accepted_date' => Helper::getFormattedDateObject(now()->format('Y-m-d H:i:s'), 'datetime', false),
			'declined_date' => Helper::getFormattedDateObject(now()->format('Y-m-d H:i:s'), 'datetime', false),
			'assigned_to' => $assigned_user?->display_name,
			'email' => $assigned_user?->email,
			'employee_num' => $assigned_user?->employee_num,
			'site_name' => $settings->site_name,
			'company_name' => $item->company?->name ?? $settings->site_name,
			'signature' => ($sig_filename && isset($encoded_image[1])) ? $encoded_image[1] : null,  // ✅ FIX
			'logo' => $encoded_logo ?? null,
			'date_settings' => $settings->date_display_format,
			'admin' => auth()->user()->present()?->fullName,
			'qty' => $acceptance->qty ?? 1,
		];

		if ($request->input('asset_acceptance') === 'accepted') {

			// ✅ Accept = status -> With Department
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

			// log acceptance (PDF filename empty is ok)
			$acceptance->accept($sig_filename, $item->getEula(), $pdf_filename, $request->input('note'));
			$return_msg = trans('admin/users/message.accepted');

		} else {

			for ($i = 0; $i < ($acceptance->qty ?? 1); $i++) {
				$acceptance->decline($sig_filename, $request->input('note'));
			}

			// ✅ Decline = status -> Requested
			if ($acceptance->checkoutable_type === \App\Models\Asset::class) {
				$requestedId = Statuslabel::where('name', 'Requested')->value('id');
				if ($requestedId) {
					$item->status_id = $requestedId;
					$item->save();
				}
			}

			$return_msg = trans('admin/users/message.declined');
		}

		// Send an email notification if one is requested
		if ($acceptance->alert_on_response_id) {
			try {
				$recipient = User::find($acceptance->alert_on_response_id);

				if ($recipient) {
					Mail::to($recipient)->send(new CheckoutAcceptanceResponseMail(
						$acceptance,
						$recipient,
						$request->input('asset_acceptance') === 'accepted',
					));
				}
			} catch (Exception $e) {
				Log::error($e->getMessage());
				Log::warning($e);
			}
		}

		
		// Mark the "acceptance required" database notification as read for this specific asset
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

}
