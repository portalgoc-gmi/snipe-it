@extends('layouts/default')

@section('title')
    Returns :: @parent
@stop

@section('content')
@php
    use Illuminate\Support\Facades\Gate;

    $me = auth()->user();
    $isSecretary = $me && $me->groups()->where('name', 'Secretary')->exists();

    $allowedGroups = ['Warehouse keeper'];

    $canWarehouse = $me && (
        Gate::allows('admin') ||
        Gate::allows('superadmin') ||
        (method_exists($me, 'isSuperUser') && $me->isSuperUser()) ||
        $me->groups()->whereIn('name', $allowedGroups)->exists()
    );
@endphp

<div class="row">
    <div class="col-md-12">
        <div class="box">
            <div class="box-body">
                <h3 style="margin-top:0;">Returns</h3>

                @if($returns->isEmpty())
                    <p class="text-muted">No open returns.</p>
                @else
                    <div class="table-responsive">
                        <table id="returnsTable" class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Asset</th>
                                    <th>Requested At</th>
                                    <th>In Transit</th>
                                    <th>Received</th>
                                    <th style="width:240px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($returns as $r)
                                    <tr>
                                        <td>
                                            @if($r->asset)
                                                <a href="{{ url('hardware/'.$r->asset->id) }}">
                                                    {{ $r->asset->name ?? 'Asset' }}
                                                    ({{ $r->asset->asset_tag ?? $r->asset->id }})
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
                                            @if(!empty($r->in_transit_at))
                                                <span class="label label-info">
                                                    {{ \Carbon\Carbon::parse($r->in_transit_at)->format('Y-m-d H:i') }}
                                                </span>
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
                                            @if($isSecretary && empty($r->in_transit_at) && !$r->received_at)
                                                <form method="POST" action="{{ route('returns.in-transit', $r) }}" style="display:inline;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-xs btn-info">Mark In Transit</button>
                                                </form>
                                            @endif

                                            @if($canWarehouse && !empty($r->in_transit_at) && !$r->received_at)
                                                <form method="POST" action="{{ route('returns.received', $r) }}" style="display:inline;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-xs btn-success">Mark Received</button>
                                                </form>
                                            @endif

                                            @if($canWarehouse && $r->received_at && !$r->checked_in_at && $r->asset)
                                                <a class="btn btn-xs btn-primary" href="{{ route('hardware.checkin.create', $r->asset->id) }}?return_id={{ $r->id }}">
                                                    Check-in
                                                </a>
                                            @endif

                                            @if($canWarehouse && $r->checked_in_at && !$r->closed_at)
                                                <form method="POST" action="{{ route('returns.close', $r) }}" style="display:inline;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-xs btn-default">Close</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@stop

@push('js')
<script>
    function refreshReturnsRows() {
        $.get("{{ route('returns.rows') }}", function (html) {
            $('#returnsTable tbody').html(html);
        });
    }

    refreshReturnsRows();
    setInterval(refreshReturnsRows, 10000);
</script>
@endpush
