@extends('layouts/default')

@section('title')
{{ trans('floating-licenses::floating.enable') }}
@parent
@stop

@section('content')
<div class="row">
    <div class="col-md-6 col-md-offset-3">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('floating-licenses::floating.enable') }}</h3>
            </div>
            <form method="POST" action="" id="floating-enable-form">
                @csrf
                <div class="box-body">
                    <div class="form-group">
                        <label for="license_id">{{ trans('floating-licenses::floating.select_license') }}</label>
                        <select name="license_id" id="license_id" class="form-control" required>
                            @foreach ($licenses as $license)
                            <option value="{{ $license->id }}">{{ $license->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @include('floating-licenses::partials.config-fields', ['config' => null])
                </div>
                <div class="box-footer">
                    <button type="submit" class="btn btn-primary">{{ trans('floating-licenses::floating.enable') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@stop

@section('moar_scripts')
<script>
    document.getElementById('license_id').addEventListener('change', function () {
        document.getElementById('floating-enable-form').action =
            '{{ url('floating-licenses/licenses') }}/' + this.value + '/enable';
    });
    document.getElementById('license_id').dispatchEvent(new Event('change'));
</script>
@stop
