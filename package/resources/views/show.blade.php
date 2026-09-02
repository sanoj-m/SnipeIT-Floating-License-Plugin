@extends('layouts/default')

@section('title')
{{ $config->license?->name }}
@parent
@stop

@section('content')
<div class="row">
    <div class="col-md-4">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $config->license?->name }}</h3>
            </div>
            <div class="box-body">
                <table class="table">
                    <tr>
                        <th>{{ trans('floating-licenses::floating.type') }}</th>
                        <td>{{ trans('floating-licenses::floating.type') }}</td>
                    </tr>
                    <tr>
                        <th>{{ trans('floating-licenses::floating.pool_size') }}</th>
                        <td>{{ $availability['pool_size'] }}</td>
                    </tr>
                    <tr>
                        <th>{{ trans('floating-licenses::floating.active') }}</th>
                        <td>{{ $availability['active'] }}</td>
                    </tr>
                    <tr>
                        <th>{{ trans('floating-licenses::floating.available') }}</th>
                        <td>{{ $availability['available'] }}</td>
                    </tr>
                    @can('floating_licenses.costs')
                    <tr>
                        <th>{{ trans('floating-licenses::floating.total_cost') }}</th>
                        <td>{{ $config->total_cost !== null ? number_format((float) $config->total_cost, 2) : '' }}</td>
                    </tr>
                    <tr>
                        <th>{{ trans('floating-licenses::floating.cost_per_slot') }}</th>
                        <td>{{ number_format($costPerSlot, 2) }}</td>
                    </tr>
                    @endcan
                    <tr>
                        <th>{{ trans('floating-licenses::floating.cost_mode') }}</th>
                        <td>{{ trans('floating-licenses::floating.cost_mode_'.$config->cost_mode) }}</td>
                    </tr>
                    <tr>
                        <th>{{ trans('floating-licenses::floating.allow_over_allocation') }}</th>
                        <td>{{ $config->allow_over_allocation ? trans('general.yes') : trans('general.no') }}</td>
                    </tr>
                    @if ($config->lease_duration_minutes !== null)
                    <tr>
                        <th>{{ trans('floating-licenses::floating.lease_duration_minutes') }}</th>
                        <td>{{ $config->lease_duration_minutes }}</td>
                    </tr>
                    @endif
                    @if ($config->idle_timeout_minutes !== null)
                    <tr>
                        <th>{{ trans('floating-licenses::floating.idle_timeout_minutes') }}</th>
                        <td>{{ $config->idle_timeout_minutes }}</td>
                    </tr>
                    @endif
                </table>

                @can('floating_licenses.manage')
                <a href="{{ route('floating-licenses.edit', $config) }}" class="btn btn-warning">
                    {{ trans('general.edit') }}
                </a>
                <form method="POST" action="{{ route('floating-licenses.destroy', $config) }}" style="display:inline"
                      onsubmit="return confirm('{{ trans('floating-licenses::floating.confirm_disable') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">{{ trans('floating-licenses::floating.disable') }}</button>
                </form>
                @endcan
            </div>
        </div>

        @can('floating_licenses.allocate')
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('floating-licenses::floating.allocate') }}</h3>
            </div>
            <div class="box-body">
                <form method="POST" action="{{ route('floating-licenses.allocate', $config) }}">
                    @csrf
                    <div class="form-group">
                        <label for="user_id">{{ trans('floating-licenses::floating.user') }}</label>
                        <input type="number" name="user_id" id="user_id" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="asset_id">{{ trans('floating-licenses::floating.asset') }}</label>
                        <input type="number" name="asset_id" id="asset_id" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="notes">{{ trans('floating-licenses::floating.notes') }}</label>
                        <textarea name="notes" id="notes" class="form-control"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">{{ trans('floating-licenses::floating.allocate') }}</button>
                </form>
            </div>
        </div>
        @endcan
    </div>

    <div class="col-md-8">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('floating-licenses::floating.active_allocations') }}</h3>
            </div>
            <div class="box-body">
                @if ($activeAllocations->isEmpty())
                    <p>{{ trans('floating-licenses::floating.no_allocations') }}</p>
                @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('floating-licenses::floating.user') }}</th>
                            <th>{{ trans('floating-licenses::floating.license') }}</th>
                            <th>{{ trans('floating-licenses::floating.asset') }}</th>
                            <th>{{ trans('floating-licenses::floating.allocated_at') }}</th>
                            <th>{{ trans('floating-licenses::floating.last_seen_at') }}</th>
                            <th>{{ trans('floating-licenses::floating.expires_at') }}</th>
                            @can('floating_licenses.costs')
                            <th>{{ trans('floating-licenses::floating.allocated_cost') }}</th>
                            @endcan
                            <th>{{ trans('floating-licenses::floating.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activeAllocations as $allocation)
                        <tr>
                            <td>{{ $allocation->user?->display_name ?? $allocation->user_id }}</td>
                            <td>{{ $config->license?->name }}</td>
                            <td>{{ $allocation->asset?->present()?->name() ?? $allocation->asset?->asset_tag }}</td>
                            <td>{{ $allocation->allocated_at }}</td>
                            <td>{{ $allocation->last_seen_at }}</td>
                            <td>{{ $allocation->expires_at }}</td>
                            @can('floating_licenses.costs')
                            <td>{{ $allocation->allocated_cost !== null ? number_format((float) $allocation->allocated_cost, 2) : '' }}</td>
                            @endcan
                            <td>
                                @if (($allocation->user_id === auth()->id()) || Gate::allows('floating_licenses.release'))
                                <form method="POST" action="{{ route('floating-licenses.allocations.release', $allocation) }}" style="display:inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-warning">{{ trans('floating-licenses::floating.release') }}</button>
                                </form>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>

        @can('floating_licenses.history')
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('floating-licenses::floating.history') }}</h3>
            </div>
            <div class="box-body">
                @if ($history->isEmpty())
                    <p>{{ trans('floating-licenses::floating.no_history') }}</p>
                @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('floating-licenses::floating.user') }}</th>
                            <th>{{ trans('floating-licenses::floating.status') }}</th>
                            <th>{{ trans('floating-licenses::floating.allocated_at') }}</th>
                            <th>{{ trans('floating-licenses::floating.released_at') }}</th>
                            @can('floating_licenses.costs')
                            <th>{{ trans('floating-licenses::floating.allocated_cost') }}</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $allocation)
                        <tr>
                            <td>{{ $allocation->user?->display_name ?? $allocation->user_id }}</td>
                            <td>{{ $allocation->status }}</td>
                            <td>{{ $allocation->allocated_at }}</td>
                            <td>{{ $allocation->released_at }}</td>
                            @can('floating_licenses.costs')
                            <td>{{ $allocation->allocated_cost !== null ? number_format((float) $allocation->allocated_cost, 2) : '' }}</td>
                            @endcan
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>
        @endcan
    </div>
</div>
@stop
