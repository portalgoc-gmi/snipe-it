<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class AcceptanceAssetPendingNotification extends Notification
{
    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $groupCount = $this->data['group_count'] ?? 1;
        $groupKey   = $this->data['group_key'] ?? null;

        return [
	    'type'        => 'acceptance_required',
	    'title'       => $groupCount > 1
		? $groupCount . ' files require acceptance'
		: 'File Acceptance Required',
	    'message'     => $groupCount > 1
		? "{$this->data['assigned_to']} received {$groupCount} files and must accept or decline them."
		: "{$this->data['assigned_to']} received file {$this->data['item_tag']} and must accept or decline it.",
	    'icon'        => 'fas fa-check-circle',
	    'color'       => 'green',
	    'item_id'     => $this->data['item_id'] ?? null,
	    'group_key'   => $groupKey,
	    'group_count' => $groupCount,
	    'url'         => url('/account/accept'),
	];
    }
}
