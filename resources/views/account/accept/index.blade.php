@extends('layouts/default')

@section('title')
{{ trans('general.accept_assets', array('name' => '')) }}
@parent
@stop

@section('content')
<div class="row">
  <div class="col-md-12">

    <div class="box box-default">
      <div class="box-header with-border">
        <h3 class="box-title">My Items</h3>
      </div>
      <div class="box-body">
      <div id="myAcceptToolbar" style="margin-bottom: 15px;">
	    <button type="submit" id="bulkAcceptBtn" class="btn btn-success" form="bulkAcceptForm">
		Accept Selected
	    </button>
	</div>

	<form method="POST" action="{{ route('account.accept.bulk') }}" id="bulkAcceptForm">
	    {{ csrf_field() }}
        <table
          data-cookie-id-table="myPendingAcceptances"
          data-id-table="myPendingAcceptances"
          data-side-pagination="client"
          data-show-refresh="false"
          data-sort-order="asc"
          id="myPendingAcceptances"
          class="table table-striped snipe-table"
          data-export-options='{
            "fileName": "my-pending-acceptances-{{ date('Y-m-d') }}",
            "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","icon"]
          }'>
          <thead>
            <tr>
		  <th><input type="checkbox" id="checkAllMyAcceptances"></th>
		  <th>{{ trans('general.name')}}</th>
		  <th>{{ trans('general.type')}}</th>
		  <th>{{ trans('general.category')}}</th>
		  <th>{{ trans('general.qty') }}</th>
		  <th>{{ trans('general.serial_number')}}</th>
		  <th>{{ trans('table.actions')}}</th>
	    </tr>
          </thead>
          <tbody>
            @foreach ($myAcceptances as $acceptance)
              <tr>
                @if ($acceptance->checkoutable)
                  <td>
		    <input
			type="checkbox"
			class="acceptance-check"
			name="selected_acceptances[]"
			value="{{ $acceptance->id }}"
			data-assigned-to="{{ $acceptance->assigned_to_id }}"
		    >
		  </td>
                  <td>{{ $acceptance->checkoutable->present()->name }}</td>
                  <td>{{ $acceptance->checkoutable_item_type }}</td>
                  <td>{{ $acceptance->checkoutable_category_name ?? '' }}</td>
                  <td>{{ $acceptance->qty ?? '1' }}</td>
                  <td>{{ $acceptance->checkoutable->serial ?? '' }}</td>
                  <td>
                    <a href="{{ route('account.accept.item', $acceptance) }}" class="btn btn-theme btn-sm single-accept-btn">
                      {{ trans('general.accept_decline') }}
                    </a>
                  </td>
                @else
                  <td>-----</td>
                  <td>{{ trans('general.error_user_company_accept_view') }}</td>
                  <td></td>
                  <td></td>
                  <td></td>
                  <td></td>
                @endif
              </tr>
            @endforeach
          </tbody>
        </table>
        </form>
      </div>
    </div>

{{--

    <div class="box box-default">
      <div class="box-header with-border">
        <h3 class="box-title">Department Items</h3>
      </div>
	 
	 <div class="box-body">
	    <div id="departmentAcceptToolbar" style="margin-bottom: 15px;">
		<button type="submit" id="bulkDepartmentAcceptBtn" class="btn btn-success" form="bulkDepartmentAcceptForm">
		    Accept Department Selected
		</button>
	    </div>

	    <form method="POST" action="{{ route('account.accept.bulk') }}" id="bulkDepartmentAcceptForm">
		{{ csrf_field() }}

		<table
		  data-cookie-id-table="departmentPendingAcceptances"
		  data-id-table="departmentPendingAcceptances"
		  data-side-pagination="client"
		  data-show-refresh="false"
		  data-sort-order="asc"
		  id="departmentPendingAcceptances"
		  class="table table-striped snipe-table"
		  data-export-options='{
		    "fileName": "department-pending-acceptances-{{ date('Y-m-d') }}",
		    "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","icon"]
		  }'>
		  <thead>
		    <tr>
		      <th><input type="checkbox" id="checkAllDepartmentAcceptances"></th>
		      <th>{{ trans('general.name')}}</th>
		      <th>Requested For</th>
		      <th>{{ trans('general.type')}}</th>
		      <th>{{ trans('general.category')}}</th>
		      <th>{{ trans('general.qty') }}</th>
		      <th>{{ trans('general.serial_number')}}</th>
		      <th>{{ trans('table.actions')}}</th>
		    </tr>
		  </thead>
		  <tbody>
		    @foreach ($departmentAcceptances as $acceptance)
		      <tr>
		        @if ($acceptance->checkoutable)
		          <td>
		            <input
		              type="checkbox"
		              class="department-acceptance-check"
		              name="selected_acceptances[]"
		              value="{{ $acceptance->id }}"
		            >
		          </td>
		          <td>{{ $acceptance->checkoutable->present()->name }}</td>
		          <td>{{ optional($acceptance->assignedTo)->display_name ?? '-' }}</td>
		          <td>{{ $acceptance->checkoutable_item_type }}</td>
		          <td>{{ $acceptance->checkoutable_category_name ?? '' }}</td>
		          <td>{{ $acceptance->qty ?? '1' }}</td>
		          <td>{{ $acceptance->checkoutable->serial ?? '' }}</td>
		          <td>
		            <a href="{{ route('account.accept.item', $acceptance) }}" class="btn btn-theme btn-sm department-single-accept-btn">
		              {{ trans('general.accept_decline') }}
		            </a>
		          </td>
		        @else
		          <td>-----</td>
		          <td>{{ trans('general.error_user_company_accept_view') }}</td>
		          <td>-</td>
		          <td></td>
		          <td></td>
		          <td></td>
		          <td></td>
		          <td></td>
		        @endif
		      </tr>
		    @endforeach
		  </tbody>
		</table>
	    </form>
	</div>
    </div>

--}}

  </div>
</div>
@stop

@section('moar_scripts')
@include ('partials.bootstrap-table')

<script nonce="{{ csrf_token() }}">
    function selectedMyAcceptanceCheckboxes() {
        return document.querySelectorAll('.acceptance-check:checked');
    }

    /*
    DEPARTMENT LOGIC OFF
    function selectedDepartmentAcceptanceCheckboxes() {...}
    function syncDepartmentAcceptHeaderCheckbox() {...}
    */

    function toggleSingleAcceptButtons() {
        let mySelectedCount = selectedMyAcceptanceCheckboxes().length;

        document.querySelectorAll('.single-accept-btn').forEach(function(btn) {
            btn.classList.toggle('disabled', mySelectedCount >= 1);
            btn.style.pointerEvents = mySelectedCount >= 1 ? 'none' : '';
            btn.style.opacity = mySelectedCount >= 1 ? '0.5' : '';
        });

        /*
        DEPARTMENT SINGLE BUTTONS OFF
        document.querySelectorAll('.department-single-accept-btn')...
        */
    }

    function toggleBulkButtons() {
        let myBtn = document.getElementById('bulkAcceptBtn');

        if (myBtn) {
            myBtn.disabled = false;
            myBtn.style.pointerEvents = '';
            myBtn.style.opacity = '';
        }

        /*
        DEPARTMENT BULK BUTTON OFF
        let deptBtn = document.getElementById('bulkDepartmentAcceptBtn');
        */
    }

    function syncMyAcceptHeaderCheckbox() {
        let all = document.querySelectorAll('.acceptance-check');
        let checked = document.querySelectorAll('.acceptance-check:checked');
        let header = document.getElementById('checkAllMyAcceptances');

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
        if (e.target && e.target.id === 'checkAllMyAcceptances') {
            let checked = e.target.checked;

            document.querySelectorAll('.acceptance-check').forEach(function(cb) {
                cb.checked = checked;
            });

            toggleSingleAcceptButtons();
            toggleBulkButtons();
            syncMyAcceptHeaderCheckbox();
        }

        if (e.target && e.target.matches('.acceptance-check')) {
            toggleSingleAcceptButtons();
            toggleBulkButtons();
            syncMyAcceptHeaderCheckbox();
        }

        /*
        DEPARTMENT CHANGE LOGIC OFF
        */
    });

    document.addEventListener('submit', function(e) {
        if (e.target && e.target.id === 'bulkAcceptForm') {
            let checked = selectedMyAcceptanceCheckboxes();

            if (checked.length === 0) {
                e.preventDefault();
                alert('Please select at least one item from My Items.');
                return false;
            }
        }

        /*
        DEPARTMENT SUBMIT LOGIC OFF
        */
    });

    document.addEventListener('DOMContentLoaded', function() {
        toggleSingleAcceptButtons();
        toggleBulkButtons();
        syncMyAcceptHeaderCheckbox();

        /*
        DEPARTMENT HEADER SYNC OFF
        */
    });
</script>

@stop
