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
        return [
            'type' => 'asset_declined_recheckout',
            'title' => '❌ Declined acceptance',
            'message' => 'The user did not receive this file. Please checkout it again.',
            'asset_id' => $this->data['asset_id'] ?? null,
            'asset_tag' => $this->data['asset_tag'] ?? null,
            'asset_name' => $this->data['asset_name'] ?? null,
            'declined_by' => $this->data['declined_by'] ?? null,
            'note' => $this->data['note'] ?? null,
        ];
    }
}
