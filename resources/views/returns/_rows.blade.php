@foreach($returns as $r)
  <tr>
    <td>
      @if($r->asset)
        <a href="{{ url('hardware/'.$r->asset->id) }}">
          {{ $r->asset->name ?? 'Asset' }} ({{ $r->asset->asset_tag ?? $r->asset->id }})
        </a>
      @else
        <span class="text-muted">(missing asset)</span>
      @endif
    </td>

    <td>
      @if($r->requested_at)
        {{ \Carbon\Carbon::parse($r->requested_at)->format('Y-m-d H:i') }}
      @else
        <span class="text-muted">—</span>
      @endif
    </td>

    <td>
      @if($r->received_at)
        <span class="label label-success">Received</span>
      @else
        <span class="label label-warning">Return Requested</span>
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
      @if($canWarehouse && !$r->received_at)
        <form method="POST" action="{{ route('returns.received', $r) }}" style="display:inline;">
          @csrf
          <button type="submit" class="btn btn-xs btn-success">Mark Received</button>
        </form>
      @endif

      @if($canWarehouse && $r->received_at && !$r->checked_in_at && $r->asset)
        <a class="btn btn-xs btn-primary" href="{{ route('hardware.checkin.create', $r->asset->id) }}?return_id={{ $r->id }}">
          Check In
        </a>
      @endif
    </td>
  </tr>
@endforeach
