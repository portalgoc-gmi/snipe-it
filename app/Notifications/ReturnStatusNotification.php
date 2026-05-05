<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ReturnStatusNotification extends Notification
{
    use Queueable;

    public string $event;
    public $return;
    public $asset;
    public $actor;
    public ?int $bulkCount;

    public function __construct(string $event, $return, $asset = null, $actor = null, ?int $bulkCount = null)
    {
        $this->event = $event;
        $this->return = $return;
        $this->asset = $asset ?: ($return->asset ?? null);
        $this->actor = $actor;
        $this->bulkCount = $bulkCount;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $assetName = $this->asset?->name ?? 'File';
        $assetTag  = $this->asset?->asset_tag ?? ($this->asset?->id ?? '');
        $who       = $this->actor?->display_name ?? $this->actor?->username ?? 'System';

        $type = match ($this->event) {
            'requested'  => 'return_requested',
            'in_transit' => 'return_in_transit',
            'received'   => 'return_received',
            default      => 'return_update',
        };

        $message = match ($this->event) {
	    'requested'  => (!empty($this->bulkCount) && $this->bulkCount > 1)
		? "{$this->bulkCount} files were requested for return by {$who}."
		: "Return requested for file {$assetName} ({$assetTag}) by {$who}.",

	    'in_transit' => "File {$assetName} ({$assetTag}) is in transit.",

	    'received'   => (!empty($this->bulkCount) && $this->bulkCount > 1)
		? "{$this->bulkCount} files were received."
		: "File {$assetName} ({$assetTag}) was received.",

	    default      => "Return updated for file {$assetName} ({$assetTag}).",
	};

        if ($this->event === 'requested' && !empty($this->bulkCount) && $this->bulkCount > 1) {
	    $message = "{$this->bulkCount} assets were requested for return to archive by {$who}.";
	} elseif ($this->event === 'received' && !empty($this->bulkCount) && $this->bulkCount > 1) {
	    $message = "{$this->bulkCount} assets were marked as received by {$who}.";
	} else {
	    $message = match ($this->event) {
		'requested'  => "Return requested for {$assetName} ({$assetTag}) by {$who}.",
		'in_transit' => "Return marked In Transit for {$assetName} ({$assetTag}) by {$who}.",
		'received'   => "Warehouse received {$assetName} ({$assetTag}) (by {$who}).",
		default      => "Return updated for {$assetName} ({$assetTag}) by {$who}.",
	    };
	}

        $url = ($this->event === 'received')
            ? '/hardware/' . ($this->asset?->id)
            : '/returns';
	
	$title = match ($this->event) {
	    'requested'  => 'Return to Archive Requested',
	    'in_transit' => 'Return In Transit',
	    'received'   => 'Return Received',
	    default      => 'Return Update',
	};

        return [
            'type'       => $type,
            'title'      => $title,
            'message'    => $message,
            'icon' => 'fas fa-archive',
            'color' => 'orange',
            'url'        => $url,
            'event'      => $this->event,
            'return_id'  => $this->return?->id,
            'asset_id'   => $this->asset?->id,
            'bulk_count' => $this->bulkCount,
        ];
    }
}
