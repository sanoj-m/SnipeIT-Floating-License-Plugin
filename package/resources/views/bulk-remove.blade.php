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
                        <input type="text" id="bulkUserFilter" class="form-control" placeholder="{{ trans('general.search') }}" style="margin-bottom:12px;">

                        <div class="checkbox" style="margin:0 0 10px 0; padding-bottom:10px; border-bottom:1px solid #e5e5e5;">
                            <label>
                                <input type="checkbox" id="bulkUserSelectAll">
                                <strong>{{ trans('general.select_all_none') }}</strong>
                                (<span id="bulkUserSelectedCount">0</span> {{ trans('general.selected') }})
                            </label>
                        </div>

                        <div style="max-height:420px; overflow-y:auto; border:1px solid #d2d6de; border-radius:4px; padding:4px 14px;">
                            @if ($floatingUsers->isNotEmpty())
                                <h4 style="margin:8px 14px 4px;">{{ trans('floating-licenses::floating.floating_assignments') }}</h4>
                                @foreach ($floatingUsers as $user)
                                    <div class="checkbox bulk-user-row" style="padding-top:6px; padding-bottom:6px; margin:0; border-bottom:1px solid #f2f2f2;" data-search="{{ strtolower($user->display_name . ' ' . $user->username) }}">
                                        <label>
                                            <input type="checkbox" name="user_ids[]" value="{{ $user->id }}" class="bulk-user-checkbox">
                                            {{ $user->display_name }} ({{ $user->username }})
                                        </label>
                                    </div>
                                @endforeach
                            @endif
                            @if ($seatUsers->isNotEmpty())
                                <h4 style="margin:12px 14px 4px;">{{ trans('floating-licenses::floating.seat_assignments') }}</h4>
                                @foreach ($seatUsers as $user)
                                    <div class="checkbox bulk-user-row" style="padding-top:6px; padding-bottom:6px; margin:0; border-bottom:1px solid #f2f2f2;" data-search="{{ strtolower($user->display_name . ' ' . $user->username) }}">
                                        <label>
                                            <input type="checkbox" name="user_ids[]" value="{{ $user->id }}" class="bulk-user-checkbox">
                                            {{ $user->display_name }} ({{ $user->username }})
                                        </label>
                                    </div>
                                @endforeach
                            @endif
                        </div>

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

@if ($floatingUsers->isNotEmpty() || $seatUsers->isNotEmpty())
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var filter = document.getElementById('bulkUserFilter');
        var selectAll = document.getElementById('bulkUserSelectAll');
        var countEl = document.getElementById('bulkUserSelectedCount');

        function visibleBoxes() {
            return Array.prototype.filter.call(
                document.querySelectorAll('.bulk-user-checkbox'),
                function (box) { return box.closest('.bulk-user-row').style.display !== 'none'; }
            );
        }

        function refreshCount() {
            countEl.textContent = document.querySelectorAll('.bulk-user-checkbox:checked').length;
        }

        filter.addEventListener('input', function () {
            var q = filter.value.toLowerCase();
            document.querySelectorAll('.bulk-user-row').forEach(function (row) {
                row.style.display = row.dataset.search.indexOf(q) === -1 ? 'none' : '';
            });
        });

        selectAll.addEventListener('change', function () {
            visibleBoxes().forEach(function (box) { box.checked = selectAll.checked; });
            refreshCount();
        });

        document.addEventListener('change', function (event) {
            if (event.target.matches('.bulk-user-checkbox')) { refreshCount(); }
        });
    });
</script>
@endif
@stop
