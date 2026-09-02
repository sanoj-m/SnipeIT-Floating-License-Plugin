@extends('layouts/default')

@section('title')
{{ trans('floating-licenses::floating.title') }}
@parent
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('floating-licenses::floating.title') }}</h3>
                <div class="box-tools pull-right">
                    @can('floating_licenses.manage')
                    <a href="{{ route('floating-licenses.create') }}" class="btn btn-sm btn-primary">
                        {{ trans('floating-licenses::floating.enable') }}
                    </a>
                    @endcan
                </div>
            </div>
            <div class="box-body">
                @if ($configs->isEmpty())
                    <p>{{ trans('floating-licenses::floating.no_pools') }}</p>
                @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('floating-licenses::floating.license') }}</th>
                            <th>{{ trans('floating-licenses::floating.pool_size') }}</th>
                            <th>{{ trans('floating-licenses::floating.active') }}</th>
                            <th>{{ trans('floating-licenses::floating.available') }}</th>
                            @can('floating_licenses.costs')
                            <th>{{ trans('floating-licenses::floating.cost_per_slot') }}</th>
                            @endcan
                            <th>{{ trans('floating-licenses::floating.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($configs as $config)
                        <tr>
                            <td>
                                <a href="{{ route('floating-licenses.show', $config) }}">
                                    {{ $config->license?->name }}
                                </a>
                            </td>
                            <td>{{ $stats[$config->id]['pool_size'] }}</td>
                            <td>{{ $stats[$config->id]['active'] }}</td>
                            <td>{{ $stats[$config->id]['available'] }}</td>
                            @can('floating_licenses.costs')
                            <td>{{ number_format($stats[$config->id]['cost_per_slot'], 2) }}</td>
                            @endcan
                            <td>
                                <a href="{{ route('floating-licenses.show', $config) }}" class="btn btn-sm btn-default">
                                    {{ trans('general.view') }}
                                </a>
                                @can('floating_licenses.manage')
                                <a href="{{ route('floating-licenses.edit', $config) }}" class="btn btn-sm btn-warning">
                                    {{ trans('general.edit') }}
                                </a>
                                @endcan
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>
    </div>
</div>
@stop
