<?php

namespace App\Notifications;

use App\Helpers\Helper;
use App\Models\Setting;
use Illuminate\Notifications\Notification;

class RequestAssetNotification extends Notification
{
    public function __construct($params)
	{
	    $this->target = $params['target'] ?? null;
	    $this->requester = $params['requester'] ?? $this->target;
	    $this->requested_for = $params['requested_for'] ?? null;
	    $this->item = $params['item'] ?? null;
	    $this->item_type = $params['item_type'] ?? null;
	    $this->item_quantity = $params['item_quantity'] ?? 1;
	    $this->note = $params['note'] ?? '';
	    $this->requested_date = Helper::getFormattedDateObject(
		$params['requested_date'] ?? now(),
		'datetime',
		false
	    );
	    $this->settings = Setting::getSettings();

	    $this->title = $params['title'] ?? null;
	    $this->message = $params['message'] ?? null;
	    $this->item_name = $params['item_name'] ?? null;
	}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
	{
	    $requester = $this->requester;

	    if (!empty($this->item)) {
		$location = $this->item->location ?? $this->item->rtdlocation ?? null;
		$manager = $location?->manager ?? $location?->managerUser ?? null;
		$itemName = $this->item->display_name ?? $this->item->name ?? 'Item';
		$locName  = $location?->name ?? 'Unknown location';
		$mgrName  = $manager?->display_name ?? $manager?->name ?? null;

		$message = "{$requester->display_name} requested {$itemName} from {$locName}";

		if ($mgrName) {
		    $message .= " (Manager: {$mgrName})";
		}

		if (!empty($this->item_quantity) && (int)$this->item_quantity > 1) {
		    $message .= " | Qty: {$this->item_quantity}";
		}

		return [
		    'type' => 'asset_request',
		    'title' => 'New asset request',
		    'message' => $message,
		    'item_name' => $itemName,
		    'item_id' => $this->item->id,
		    'item_type' => $this->item_type,
		    'quantity' => $this->item_quantity,
		    'requested_by' => $requester->display_name,
		    'requested_by_id' => $requester->id,
		    'requested_date' => $this->requested_date,
		    'note' => $this->note,
		    'location_id' => $location?->id,
		    'location_name' => $locName,
		    'location_manager_id' => $manager?->id,
		    'location_manager_name' => $mgrName,
		    'url' => url('/hardware/requested'),
		];
	    }

	    return [
		'type' => 'asset_request_bulk',
		'title' => $this->title ?? 'New asset requests',
		'message' => $this->message ?? 'Multiple asset requests created.',
		'item_name' => $this->item_name ?? 'Multiple assets',
		'item_id' => null,
		'item_type' => 'bulk',
		'quantity' => $this->item_quantity ?? null,
		'requested_by' => $requester->display_name,
		'requested_by_id' => $requester->id,
		'requested_date' => $this->requested_date,
		'note' => $this->note,
		'url' => url('/hardware/requested'),
	    ];
	}
}
