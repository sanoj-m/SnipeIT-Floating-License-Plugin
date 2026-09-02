@extends('layouts/default')

@section('title')
{{ trans('floating-licenses::floating.bulk_remove') }} - {{ $license->name }}
@parent
@stop

@section('content')
<div class="row">
    <div class="col-md-6 col-md-offset-3">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('floating-licenses::floating.bulk_remove') }} - {{ $license->name }}</h3>
            </div>
            @if ($floatingUsers->isEmpty() && $seatUsers->isEmpty())
                <div class="box-body">
                    <p>{{ trans('floating-licenses::floating.no_assigned_users') }}</p>
                </div>
                <div class="box-footer">
                    <a href="{{ route('licenses.show', $license) }}" class="btn btn-default">{{ trans('general.cancel') }}</a>
                </div>
            @else
                <form method="POST" action="{{ route('floating-licenses.license.bulk-remove', $license) }}">
                    @csrf
                    <div class="box-body">
                        @if ($floatingUsers->isNotEmpty())
                            <h4>{{ trans('floating-licenses::floating.floating_assignments') }}</h4>
                            @foreach ($floatingUsers as $user)
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="user_ids[]" value="{{ $user->id }}">
                                        {{ $user->display_name }} ({{ $user->username }})
                                    </label>
                                </div>
                            @endforeach
                        @endif
                        @if ($seatUsers->isNotEmpty())
                            <h4>{{ trans('floating-licenses::floating.seat_assignments') }}</h4>
                            @foreach ($seatUsers as $user)
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="user_ids[]" value="{{ $user->id }}">
                                        {{ $user->display_name }} ({{ $user->username }})
                                    </label>
                                </div>
                            @endforeach
                        @endif
                        @if ($errors->has('user_ids'))
                            <p class="help-block has-error">{{ $errors->first('user_ids') }}</p>
                        @endif
                    </div>
                    <div class="box-footer">
                        <a href="{{ route('licenses.show', $license) }}" class="btn btn-default">{{ trans('general.cancel') }}</a>
                        <button type="submit" class="btn btn-warning">{{ trans('floating-licenses::floating.bulk_remove') }}</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
@stop
