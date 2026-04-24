@extends('layouts/default')

@section('title')
    Returns : @parent
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

                @if($returns->isEmpty())
                    <p class="text-muted">No open returns.</p>
                @else
                	
                    <div style="display:flex; gap:10px; margin-bottom:15px; align-items:center;">
			    <form method="POST" action="{{ route('returns.bulk-received') }}" id="bulkReceivedForm" style="margin:0;">
				@csrf
				<button type="submit" class="btn btn-success" id="bulkMarkReceivedBtn">
				    Mark Selected Received
				</button>
			    </form>

			    <form method="POST" action="{{ route('returns.bulk-checkin') }}" id="bulkCheckinForm" style="margin:0;">
				@csrf
				<button type="submit" class="btn btn-primary" id="bulkCheckinBtn">
				    Check In Selected
				</button>
			    </form>
			</div>
			
			
                    	<div class="table-responsive">
                    	
                        <table id="returnsTable" class="table table-striped">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="checkAllReturns"></th>
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
    function selectedReceivedCheckboxes() {
        return document.querySelectorAll('.return-check-received:checked');
    }

    function selectedCheckinCheckboxes() {
        return document.querySelectorAll('.return-check-checkin:checked');
    }

    function allVisibleReturnCheckboxes() {
        return document.querySelectorAll('.return-check');
    }

    function allCheckedReturnCheckboxes() {
        return document.querySelectorAll('.return-check:checked');
    }

    function toggleSingleButtons() {
        let receivedSelected = selectedReceivedCheckboxes().length >= 1;
        let checkinSelected = selectedCheckinCheckboxes().length >= 1;

        document.querySelectorAll('.single-received-btn').forEach(function(btn) {
            btn.disabled = receivedSelected || checkinSelected;
            btn.classList.toggle('disabled', receivedSelected || checkinSelected);
            btn.style.pointerEvents = (receivedSelected || checkinSelected) ? 'none' : '';
            btn.style.opacity = (receivedSelected || checkinSelected) ? '0.5' : '';
        });

        document.querySelectorAll('.single-checkin-btn').forEach(function(btn) {
            btn.classList.toggle('disabled', receivedSelected || checkinSelected);
            btn.style.pointerEvents = (receivedSelected || checkinSelected) ? 'none' : '';
            btn.style.opacity = (receivedSelected || checkinSelected) ? '0.5' : '';
        });
    }

    function toggleBulkButtons() {
        let receivedCount = selectedReceivedCheckboxes().length;
        let checkinCount = selectedCheckinCheckboxes().length;

        let receivedBtn = document.getElementById('bulkMarkReceivedBtn');
        let checkinBtn = document.getElementById('bulkCheckinBtn');

        if (receivedBtn) {
            receivedBtn.disabled = checkinCount > 0;
            receivedBtn.style.pointerEvents = checkinCount > 0 ? 'none' : '';
            receivedBtn.style.opacity = checkinCount > 0 ? '0.5' : '';
        }

        if (checkinBtn) {
            checkinBtn.disabled = receivedCount > 0;
            checkinBtn.style.pointerEvents = receivedCount > 0 ? 'none' : '';
            checkinBtn.style.opacity = receivedCount > 0 ? '0.5' : '';
        }
    }

    function syncReturnsHeaderCheckbox() {
        let all = allVisibleReturnCheckboxes();
        let checked = allCheckedReturnCheckboxes();
        let header = document.getElementById('checkAllReturns');

        if (!header) return;

        if (all.length === 0) {
            header.checked = false;
            header.indeterminate = false;
            return;
        }

        header.checked = all.length === checked.length;
        header.indeterminate = checked.length > 0 && checked.length < all.length;
    }

    document.addEventListener('change', function(e) {
        if (e.target && e.target.id === 'checkAllReturns') {
            let checked = e.target.checked;
            document.querySelectorAll('.return-check').forEach(function(cb) {
                cb.checked = checked;
            });
            toggleSingleButtons();
            toggleBulkButtons();
            syncReturnsHeaderCheckbox();
        }

        if (e.target && e.target.matches('.return-check')) {
            toggleSingleButtons();
            toggleBulkButtons();
            syncReturnsHeaderCheckbox();
        }
    });

    document.addEventListener('submit', function(e) {
        if (e.target && e.target.id === 'bulkReceivedForm') {
            if (selectedReceivedCheckboxes().length === 0) {
                e.preventDefault();
                alert('Please select at least one return to mark as received.');
                return false;
            }
        }

        if (e.target && e.target.id === 'bulkCheckinForm') {
            if (selectedCheckinCheckboxes().length === 0) {
                e.preventDefault();
                alert('Please select at least one return to check in.');
                return false;
            }
        }
    });

    function refreshReturnsRows() {
        $.get("{{ route('returns.rows') }}", function (html) {
            $('#returnsTable tbody').html(html);
            toggleSingleButtons();
            toggleBulkButtons();
            syncReturnsHeaderCheckbox();
        });
    }

    refreshReturnsRows();
    setInterval(refreshReturnsRows, 10000);

    document.addEventListener('DOMContentLoaded', function() {
        toggleSingleButtons();
        toggleBulkButtons();
        syncReturnsHeaderCheckbox();
    });
</script>

@endpush
