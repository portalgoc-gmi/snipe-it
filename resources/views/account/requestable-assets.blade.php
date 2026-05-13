@extends('layouts/default')

@section('title0')
  {{ trans('general.requestable_items') }}
@stop

{{-- Page title --}}
@section('title')
    @yield('title0')  @parent
@stop

{{-- Page content --}}
@section('content')

<div class="row">
    <div class="col-md-12">


        @if (($assets->count() < 1) && ($models->count() < 1))

            <div class="col-md-12">
                <div class="alert alert-info fade in">
                    <i class="fas fa-info-circle faa-pulse animated"></i>
                    <strong>{{ trans('general.notification_info') }}: </strong>
                    {{ trans('general.no_requestable') }}
                </div>
            </div>

        @else
        <div class="nav-tabs-custom">
            <ul class="nav nav-tabs">
                @if ($assets->count() > 0)
                <li class="active">
                    <a href="#assets" data-toggle="tab" title="{{ trans('general.assets') }}">{{ trans('general.assets') }}
                        <span class="badge badge-secondary"> {{ $assets->count()}}</span>
                    </a>               
                </li>
                @endif
                @if ($models->count() > 0)
                <li>
                    <a href="#models" data-toggle="tab" title="{{ trans('general.asset_models') }}">{{ trans('general.asset_models') }}
                        <span class="badge badge-secondary"> {{ $models->count()}}</span>
                    </a>                   
                </li>
                @endif
            </ul>
            <div class="tab-content">
                @if ($assets->count() > 0)
                <div class="tab-pane fade in active" id="assets">
                    <div class="row">
                        <div class="col-md-12">
                        <div id="requestBulkToolbar" style="margin-bottom: 15px;">
			    <button type="button" id="bulkRequestBtn" class="btn btn-primary">
				Request Selected
			    </button>
			</div>
                            <table
                                data-cookie-id-table="requestableAssetsListingTable"
                                data-id-table="requestableAssetsListingTable"
                                data-unique-id="id"
                                data-maintain-meta-data="true"
                                data-side-pagination="server"
                                data-show-export="false"
                                data-show-footer="false"
                                data-sort-order="asc"
                                data-sort-name="name"
                                data-toolbar="#requestBulkToolbar"
                                data-bulk-button-id="#bulkAssetEditButton"
                                data-bulk-form-id="#assetsBulkForm"
                                id="assetsListingTable"
                                class="table table-striped snipe-table"
                                data-url="{{ route('api.assets.requestable', ['requestable' => true]) }}">

                                <thead>
                                    <tr>
                                    	<th data-field="state" data-checkbox="true"></th>

                                        <th class="col-md-1" data-field="image" data-formatter="imageFormatter" data-sortable="true">{{ trans('general.image') }}</th>
                                        <th class="col-md-2" data-field="asset_tag" data-sortable="true" >{{ trans('general.asset_tag') }}</th>
                                        <th class="col-md-2" data-field="model" data-sortable="true">{{ trans('admin/hardware/table.asset_model') }}</th>
                                        <th class="col-md-2" data-field="model_number" data-sortable="true">{{ trans('admin/models/table.modelnumber') }}</th>
                                        <th class="col-md-2" data-field="name" data-sortable="true">{{ trans('admin/hardware/form.name') }}</th>
                                        <th class="col-md-3" data-field="serial" data-sortable="true">{{ trans('admin/hardware/table.serial') }}</th>
                                        <th class="col-md-2" data-field="location" data-sortable="true">{{ trans('admin/hardware/table.location') }}</th>
                                        <th class="col-md-2" data-field="status" data-sortable="true">{{ trans('admin/hardware/table.status') }}</th>
                                        <th class="col-md-2" data-field="expected_checkin" data-formatter="dateDisplayFormatter" data-sortable="true">{{ trans('admin/hardware/form.expected_checkin') }}</th>

                                        @foreach(\App\Models\CustomField::get() as $field)
                                            @if (($field->field_encrypted=='0') && ($field->show_in_requestable_list=='1'))
                                                <th class="col-md-2" data-field="custom_fields.{{ $field->db_column }}" data-sortable="true">{{ $field->name }}</th>
                                            @endif
                                        @endforeach
                                        <th class="col-md-1" data-formatter="assetRequestActionsFormatter" data-field="actions" data-sortable="false">{{ trans('table.actions') }}</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
                @endif

                @if ($models->count() > 0)
                <div class="tab-pane fade in {{ ($assets->count() == 0) ? 'active' : '' }}" id="models">
                    <div class="row">
                        <div class="col-md-12">
                                <table
                                        data-toolbar="#toolbar"
                                        class="table table-striped snipe-table"
                                        id="table"
                                        data-id-table="advancedTable"
                                        data-cookie-id-table="requestableAssets">
                                <thead>
                                    <tr role="row">
                                        <th class="col-md-1" data-sortable="true">{{ trans('general.image') }}</th>
                                        <th class="col-md-6" data-sortable="true">{{ trans('admin/hardware/table.asset_model') }}</th>
                                        <th class="col-md-3" data-sortable="true">{{ trans('admin/accessories/general.remaining') }}</th>

                                        <th class="col-md-2 actions" data-sortable="false">{{ trans('table.actions') }}</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach($models as $requestableModel)
                                        <tr>

                                                <td>

                                                    @if (($requestableModel->image) && ($requestableModel->getImageUrl()))
                                                        <a href="{{ $requestableModel->getImageUrl() }}" data-toggle="lightbox" data-type="image">
                                                            <img src="{{ $requestableModel->getImageUrl() }}" style="max-height: {{ $snipeSettings->thumbnail_max_h }}px; width: auto;" class="img-responsive">
                                                        </a>
                                                    @endif

                                                </td>

                                                <td>
                                                    @can('view', \App\Models\AssetModel::class)
                                                        <a href="{{ route('models.show', ['model' => $requestableModel->id]) }}">{{ $requestableModel->name }}</a>
                                                    @else
                                                        {{ $requestableModel->name }}
                                                    @endcan
                                                </td>

                                                <td>{{$requestableModel->assets->where('requestable', '1')->count()}}</td>

                                                <td>
                                                    <form  action="{{ route('account/request-item', ['itemType' => 'asset_model', 'itemId' => $requestableModel->id])}}" method="POST" accept-charset="utf-8">
                                                        {{ csrf_field() }}
                                                    <input type="text" style="width: 70px; margin-right: 10px;" class="form-control pull-left" name="request-quantity" value="" placeholder="{{ trans('general.qty') }}">
                                                    @if ($requestableModel->isRequestedBy(Auth::user()))
                                                        <input class="btn btn-danger btn-sm" type="submit" value="{{ trans('button.cancel') }}">
                                                    @else
                                                        <input class="btn btn-primary btn-sm" type="submit" value="{{ trans('button.request') }}">
                                                    @endif
                                                    </form>
                                                </td>
                                        </tr>

                                    @endforeach
                                </tbody>
                            </table>

                        </div>
                    </div>
                </div>
                @endif

            </div> <!-- .tab-content-->
        </div> <!-- .nav-tabs-custom -->

        @endif
    </div> <!-- .col-md-12> -->
</div> <!-- .row -->

<div class="modal fade" id="bulkRequestConfirmModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-blue">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">Confirm Request</h4>
      </div>

      <div class="modal-body">
        <p>You selected the following assets:</p>
        <div id="selectedAssetsList" style="max-height:250px; overflow-y:auto;"></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">No</button>
        <button type="button" id="confirmBulkRequestBtn" class="btn btn-primary">Yes, request</button>
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

    $( "a[name='Request']").click(function(event) {
        // event.preventDefault();
        quantity = $(this).closest('td').siblings().find('input').val();
        currentUrl = $(this).attr('href');
        // $(this).attr('href', currentUrl + '?quantity=' + quantity);
        // alert($(this).attr('href'));
    });
</script>

<script nonce="{{ csrf_token() }}">
    let selectedAssets = new Map();

    $('#assetsListingTable').on('check.bs.table', function (e, row) {
        selectedAssets.set(row.id, row);
    });

    $('#assetsListingTable').on('uncheck.bs.table', function (e, row) {
        selectedAssets.delete(row.id);
    });

    $('#assetsListingTable').on('check-all.bs.table', function (e, rows) {
        rows.forEach(row => selectedAssets.set(row.id, row));
    });

    $('#assetsListingTable').on('uncheck-all.bs.table', function (e, rows) {
        rows.forEach(row => selectedAssets.delete(row.id));
    });

    $('#assetsListingTable').on('load-success.bs.table', function () {
        let rows = $('#assetsListingTable').bootstrapTable('getData');

        rows.forEach(function (row) {
            if (selectedAssets.has(row.id)) {
                $('#assetsListingTable').bootstrapTable('checkBy', {
                    field: 'id',
                    values: [row.id]
                });
            }
        });

        toggleSingleRequestButtons();
    });

    $('#bulkRequestBtn').on('click', function () {
        if (selectedAssets.size === 0) {
            alert('Please select at least one asset.');
            return;
        }

        let selectedList = [];

        selectedAssets.forEach(function(row) {
            selectedList.push((row.asset_tag || row.id) + ' - ' + (row.name || ''));
        });

        let message = "You selected:\n\n" + selectedList.join("\n") + "\n\nDo you want to request these assets?";

        $('#selectedAssetsList').html(
	    '<ul>' + selectedList.map(item => '<li>' + item + '</li>').join('') + '</ul>'
	);

	$('#bulkRequestConfirmModal').modal('show');
	return;

    });
    
    $('#confirmBulkRequestBtn').on('click', function () {
    let form = $('<form>', {
        method: 'POST',
        action: "{{ route('account.request-assets.bulk') }}"
    });

    form.append($('<input>', {
        type: 'hidden',
        name: '_token',
        value: "{{ csrf_token() }}"
    }));

    selectedAssets.forEach(function(row) {
        form.append($('<input>', {
            type: 'hidden',
            name: 'selected_assets[]',
            value: row.id
        }));
    });

    $('body').append(form);
    form.submit();
});
    
    
</script>

<script nonce="{{ csrf_token() }}">
    function toggleSingleRequestButtons() {
        
	let disableSingle = selectedAssets.size >= 1;

        $('#assetsListingTable tbody tr').each(function () {
            let $row = $(this);
            let isSelected = $row.find('input[type="checkbox"]').is(':checked');
            let $requestBtn = $row.find('.btn').filter(function () {
                return ($(this).text() || '').trim().toLowerCase() === 'request';
            });

            if (!$requestBtn.length) {
                return;
            }

            if (disableSingle) {
                $requestBtn.prop('disabled', true)
                    .addClass('disabled')
                    .css({
                        'pointer-events': 'none',
                        'opacity': '0.6'
                    });
            } else {
                $requestBtn.prop('disabled', false)
                    .removeClass('disabled')
                    .css({
                        'pointer-events': '',
                        'opacity': ''
                    });
            }
        });
    }

    $('#assetsListingTable').on(
        'check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table load-success.bs.table',
        function () {
            setTimeout(toggleSingleRequestButtons, 50);
        }
    );

    $(document).ready(function () {
        setTimeout(toggleSingleRequestButtons, 300);
    });
</script>

@stop


