@extends('layouts/default')

@section('title0')
  {{ trans('admin/hardware/general.requested') }}
  {{ trans('general.assets') }}
@stop

@section('title')
    @yield('title0')  @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-body">
                <div class="row">
                    <div class="col-md-12">

                        <div id="requestedBulkToolbar" style="margin-bottom: 15px;">
			    <button type="submit" id="bulkCheckoutRequestedBtn" class="btn btn-success" form="bulkCheckoutForm">
				Checkout Selected
			    </button>
			</div>
                        
                        <form method="POST" action="{{ route('hardware.requested.bulk-checkout') }}" id="bulkCheckoutForm">
    			{{ csrf_field() }}
    			
                        <table
                            data-toolbar="#requestedBulkToolbar"
                            class="table table-striped snipe-table"
                            id="requestedAssets"
                            data-id-table="requestedAssets"
                            data-cookie-id-table="requestedAssets"
                            data-export-options='{
                                "fileName": "export-assetrequests-{{ date('Y-m-d') }}",
                                "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","icon"]
                            }'>

                            <thead>
                                <tr role="row">
                                    <th class="col-md-1">
					<input type="checkbox" id="checkAllRequested">
			            </th>
                                    <th class="col-md-1">{{ trans('general.image') }}</th>
                                    <th class="col-md-1">Asset ID</th>
                                    <th class="col-md-2">{{ trans('general.name') }}</th>
                                    <th class="col-md-2">{{ trans('admin/hardware/table.location') }}</th>
                                    <th class="col-md-2">{{ trans('admin/hardware/form.expected_checkin') }}</th>
                                    <th class="col-md-3">{{ trans('admin/hardware/table.requesting_user') }}</th>
                                    <th class="col-md-2">{{ trans('admin/hardware/table.requested_date') }}</th>
                                    <th class="col-md-1">{{ trans('button.actions') }}</th>
                                    <th class="col-md-1">{{ trans('general.checkout') }}</th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach ($requestedItems as $request)
                                    @if (!$request->requestable)
                                        @continue
                                    @endif

                                    @php
                                        $reqUser = $request->requestingUser();
                                    @endphp

                                    <tr data-request-id="{{ $request->id }}">
                                        
					<td>
					    <input type="checkbox" class="requested-check" name="selected_requests[]" value="{{ $request->id }}">
					</td>

                                        <td></td>
                                        <td>{{ $request->requestable->asset_tag ?? '' }}</td>
                                        <td>{{ $request->requestable->name ?? '' }}</td>
                                        <td>{{ $request->location()->name ?? '' }}</td>
                                        <td>{{ $request->expected_checkin ?? '' }}</td>
                                        <td>{{ $reqUser->name ?? '' }}</td>
                                        <td>{{ $request->created_at }}</td>

                                        <td>
                                            <a href="/hardware/{{ $request->requestable->id }}" class="btn btn-sm btn-info">
                                                View
                                            </a>
                                        </td>

                                        <td>
                                            
						<button type="submit"
						form="bulkCheckoutForm"
						name="selected_requests[]"
						value="{{ $request->id }}"
						class="btn btn-sm btn-success single-checkout-btn"
						onclick="if (document.querySelectorAll('input[name=&quot;selected_requests[]&quot;]:checked').length > 0) { alert('Use Checkout Selected when multiple rows are selected.'); return false; }">
					    Checkout
					</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
			</form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@stop

@section('moar_scripts')
    @include ('partials.bootstrap-table', [
        'exportFile' => 'requested-export',
        'search' => true,
        'clientSearch' => true,
    ])

    <script nonce="{{ csrf_token() }}">
	    function toggleSingleCheckoutButtons() {
		let selectedCount = document.querySelectorAll('input[name="selected_requests[]"]:checked').length;
		let disableSingles = selectedCount >= 1;
		let buttons = document.querySelectorAll('.single-checkout-btn');

		buttons.forEach(function(btn) {
		    if (disableSingles) {
		        btn.classList.add('disabled');
		        btn.style.pointerEvents = 'none';
		        btn.style.opacity = '0.5';
		    } else {
		        btn.classList.remove('disabled');
		        btn.style.pointerEvents = '';
		        btn.style.opacity = '';
		    }
		});
	    }

	    function syncHeaderCheckbox() {
		let rowCheckboxes = document.querySelectorAll('.requested-check');
		let checkedRows = document.querySelectorAll('.requested-check:checked');
		let headerCheckbox = document.getElementById('checkAllRequested');

		if (!headerCheckbox) return;

		if (rowCheckboxes.length === 0) {
		    headerCheckbox.checked = false;
		    headerCheckbox.indeterminate = false;
		    return;
		}

		headerCheckbox.checked = checkedRows.length === rowCheckboxes.length;
		headerCheckbox.indeterminate = checkedRows.length > 0 && checkedRows.length < rowCheckboxes.length;
	    }

	    document.addEventListener('change', function(e) {
		if (e.target && e.target.id === 'checkAllRequested') {
		    let checked = e.target.checked;

		    document.querySelectorAll('.requested-check').forEach(function(cb) {
		        cb.checked = checked;
		    });

		    toggleSingleCheckoutButtons();
		    syncHeaderCheckbox();
		}

		if (e.target && e.target.matches('input[name="selected_requests[]"]')) {
		    toggleSingleCheckoutButtons();
		    syncHeaderCheckbox();
		}
	    });

	    document.addEventListener('DOMContentLoaded', function() {
		toggleSingleCheckoutButtons();
		syncHeaderCheckbox();
	    });
	</script>
@stop
