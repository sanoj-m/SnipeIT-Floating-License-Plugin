@extends('layouts/default')

@section('title')
{{ trans('floating-licenses::floating.edit') }} - {{ $config->license?->name }}
@parent
@stop

@section('content')
<div class="row">
    <div class="col-md-6 col-md-offset-3">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('floating-licenses::floating.edit') }} - {{ $config->license?->name }}</h3>
            </div>
            <form method="POST" action="{{ route('floating-licenses.update', $config) }}">
                @csrf
                @method('PUT')
                <div class="box-body">
                    @include('floating-licenses::partials.config-fields', ['config' => $config])
                </div>
                <div class="box-footer">
                    <button type="submit" class="btn btn-primary">{{ trans('floating-licenses::floating.update') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@stop
