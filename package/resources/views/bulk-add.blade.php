@extends('layouts/default')

@section('title')
{{ trans('floating-licenses::floating.bulk_add') }} - {{ $license->name }}
@parent
@stop

@section('content')
<div class="row">
    <div class="col-md-6 col-md-offset-3">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('floating-licenses::floating.bulk_add') }} - {{ $license->name }}</h3>
            </div>
            <form method="POST" action="{{ route('floating-licenses.license.bulk-add', $license) }}">
                @csrf
                <div class="box-body">
                    <div class="form-group{{ $errors->has('user_ids') ? ' has-error' : '' }}">
                        <label for="user_ids">{{ trans('floating-licenses::floating.select_users') }}</label>
                        <select name="user_ids[]" id="user_ids" class="form-control" multiple size="15">
                            @foreach ($users as $user)
                            <option value="{{ $user->id }}">{{ $user->display_name }} ({{ $user->username }})</option>
                            @endforeach
                        </select>
                        <p class="help-block">{{ trans('floating-licenses::floating.bulk_add_help') }}</p>
                        @if ($errors->has('user_ids'))
                            <p class="help-block">{{ $errors->first('user_ids') }}</p>
                        @endif
                    </div>
                </div>
                <div class="box-footer">
                    <a href="{{ route('licenses.show', $license) }}" class="btn btn-default">{{ trans('general.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('floating-licenses::floating.bulk_add') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@stop
