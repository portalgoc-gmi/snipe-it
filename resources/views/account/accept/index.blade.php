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
                  <td>{{ $acceptance->checkoutable->present()->name }}</td>
                  <td>{{ $acceptance->checkoutable_item_type }}</td>
                  <td>{{ $acceptance->checkoutable_category_name ?? '' }}</td>
                  <td>{{ $acceptance->qty ?? '1' }}</td>
                  <td>{{ $acceptance->checkoutable->serial ?? '' }}</td>
                  <td>
                    <a href="{{ route('account.accept.item', $acceptance) }}" class="btn btn-theme btn-sm">
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
      </div>
    </div>

    <div class="box box-default">
      <div class="box-header with-border">
        <h3 class="box-title">Department Items</h3>
      </div>
      <div class="box-body">
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
                  <td>{{ $acceptance->checkoutable->present()->name }}</td>
                  <td>{{ optional($acceptance->assignedTo)->display_name ?? '-' }}</td>
                  <td>{{ $acceptance->checkoutable_item_type }}</td>
                  <td>{{ $acceptance->checkoutable_category_name ?? '' }}</td>
                  <td>{{ $acceptance->qty ?? '1' }}</td>
                  <td>{{ $acceptance->checkoutable->serial ?? '' }}</td>
                  <td>
                    <a href="{{ route('account.accept.item', $acceptance) }}" class="btn btn-theme btn-sm">
                      {{ trans('general.accept_decline') }}
                    </a>
                  </td>
                @else
                  <td>-----</td>
                  <td>-</td>
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
      </div>
    </div>

  </div>
</div>
@stop

@section('moar_scripts')
@include ('partials.bootstrap-table')
@stop
