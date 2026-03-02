<?php

namespace App\Notifications;

use App\Helpers\Helper;
use App\Models\Setting;
use Illuminate\Notifications\Notification;

class RequestAssetNotification extends Notification
{
    public function __construct($params)
    {
        // Backwards compatible field: requester user
        $this->target = $params['target'] ?? null;

        // New explicit fields
        $this->requester = $params['requester'] ?? $this->target;
        $this->requested_for = $params['requested_for'] ?? null;

        $this->item = $params['item'];
        $this->item_type = $params['item_type'] ?? null;
        $this->item_quantity = $params['item_quantity'] ?? 1;

        $this->note = $params['note'] ?? '';
        $this->requested_date = Helper::getFormattedDateObject($params['requested_date'], 'datetime', false);

        $this->settings = Setting::getSettings();
    }

    public function via($notifiable)
    {
        // Database notification for your UI page
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $requester = $this->requester;

        // Resolve location info from the asset
        $location = $this->item->location ?? $this->item->rtdlocation ?? null;

        // Try common manager relations
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
            // IMPORTANT: use a consistent type so controller can route correctly
            'type' => 'asset_request',

            // UI fields
            'title' => 'New asset request',
            'message' => $message,

            // Item fields
            'item_name' => $itemName,
            'item_id' => $this->item->id,
            'item_type' => $this->item_type,
            'quantity' => $this->item_quantity,

            // Requester fields
            'requested_by' => $requester->display_name,
            'requested_by_id' => $requester->id,
            'requested_date' => $this->requested_date,
            'note' => $this->note,

            // Extra info
            'location_id' => $location?->id,
            'location_name' => $locName,
            'location_manager_id' => $manager?->id,
            'location_manager_name' => $mgrName,

            // Where "Open" should go (warehouse / requested page)
            'url' => url('/hardware/requested'),
        ];
    }
}
