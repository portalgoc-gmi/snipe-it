@foreach($returns as $r)
  @php
    $isDeceased = $r->asset && strtolower($r->asset->_snipeit_patient_status_5 ?? '') === 'deceased';
  @endphp
  <tr class="{{ $isDeceased ? 'deceased-row' : '' }}">
    <td>
      @if($canWarehouse && !empty($r->in_transit_at) && !$r->received_at)
	    <input
	      type="checkbox"
	      class="return-check return-check-received"
	      name="selected_returns[]"
	      value="{{ $r->id }}"
	      form="bulkReceivedForm"
	    >
	@elseif($canWarehouse && $r->received_at && !$r->checked_in_at && $r->asset)
	    <input
	      type="checkbox"
	      class="return-check return-check-checkin"
	      name="selected_returns[]"
	      value="{{ $r->id }}"
	      form="bulkCheckinForm"
	    >
	@endif
    </td>

    <td>
      @if($r->asset)
        <a href="{{ url('hardware/'.$r->asset->id) }}">
          {{ $r->asset->name ?? 'Asset' }} ({{ $r->asset->asset_tag ?? $r->asset->id }})
        </a>
      @else
        <span class="text-muted">(missing asset)</span>
      @endif
    </td>
    
    <td>{{ $r->requester->name ?? '—' }}</td>
    
    <td>
      @if($r->requested_at)
        {{ \Carbon\Carbon::parse($r->requested_at)->format('Y-m-d H:i') }}
      @else
        <span class="text-muted">—</span>
      @endif
    </td>

    <td>
      @if(!empty($r->in_transit_at))
        <span class="label label-info">
          {{ \Carbon\Carbon::parse($r->in_transit_at)->format('Y-m-d H:i') }}
        </span>
      @elseif(!$r->received_at)
        <span class="label label-info">Return Requested</span>
      @else
        <span class="text-muted">—</span>
      @endif
    </td>

    <td>
      @if($r->received_at)
        <span class="label label-success">
          {{ \Carbon\Carbon::parse($r->received_at)->format('Y-m-d H:i') }}
        </span>
      @else
        <span class="text-muted">—</span>
      @endif
    </td>

    <td style="white-space:nowrap;">
	  @if($canWarehouse && !empty($r->in_transit_at) && !$r->received_at)
	    <form method="POST" action="{{ route('returns.received', $r) }}" style="display:inline;">
	      @csrf
	      <button type="submit" class="btn btn-xs btn-success single-received-btn">Mark Received</button>
	    </form>
	  @endif

	  @if($canWarehouse && $r->received_at && !$r->checked_in_at && $r->asset)
	    <a class="btn btn-xs btn-primary single-checkin-btn" href="{{ route('hardware.checkin.create', $r->asset->id) }}?return_id={{ $r->id }}">
    Check-in
</a>
	  @endif
	</td>
  </tr>
@endforeach
