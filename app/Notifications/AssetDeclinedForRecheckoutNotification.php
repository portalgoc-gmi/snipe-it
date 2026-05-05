<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AssetDeclinedForRecheckoutNotification extends Notification
{
    use Queueable;

    public function __construct(public array $data)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $assetName = $this->data['asset_name'] ?? 'File';
        $assetTag  = $this->data['asset_tag'] ?? '';
        $assetLabel = trim($assetName . (!empty($assetTag) ? ' - ' . $assetTag : ''));

        $reason = !empty($this->data['note'])
            ? ' Reason: ' . $this->data['note']
            : '';

        return [
	    'type' => 'asset_declined_recheckout',
	    'title' => 'File Acceptance Declined',
	    'message' => ($this->data['declined_by'] ?? 'User') . ' declined file ' . $assetLabel . '.' . $reason,
	    'icon' => 'fas fa-times-circle',
	    'color' => 'red',
	    'asset_id' => $this->data['asset_id'] ?? null,
	    'asset_tag' => $this->data['asset_tag'] ?? null,
	    'asset_name' => $this->data['asset_name'] ?? null,
	    'declined_by' => $this->data['declined_by'] ?? null,
	    'note' => $this->data['note'] ?? null,
	    'url' => url('/hardware/' . ($this->data['asset_id'] ?? '')),
	];
    }
}
