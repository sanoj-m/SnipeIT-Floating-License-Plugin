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
                        <label>{{ trans('floating-licenses::floating.select_users') }}</label>

                        <input type="text" id="bulkUserFilter" class="form-control" placeholder="{{ trans('general.search') }}" style="margin-bottom:12px;">

                        <div class="checkbox" style="margin:0 0 10px 0; padding-bottom:10px; border-bottom:1px solid #e5e5e5;">
                            <label>
                                <input type="checkbox" id="bulkUserSelectAll">
                                <strong>{{ trans('general.select_all_none') }}</strong>
                                (<span id="bulkUserSelectedCount">0</span> {{ trans('general.selected') }})
                            </label>
                        </div>

                        <div id="bulkUserList" style="max-height:400px; overflow-y:auto; border:1px solid #d2d6de; border-radius:4px; padding:4px 0;">
                            @foreach ($users as $user)
                            <div class="checkbox bulk-user-row" style="padding:8px 14px; margin:0; border-bottom:1px solid #f2f2f2;" data-search="{{ strtolower($user->display_name . ' ' . $user->username) }}">
                                <label style="display:block; margin:0;">
                                    <input type="checkbox" name="user_ids[]" value="{{ $user->id }}" class="bulk-user-checkbox" style="margin-right:6px;">
                                    {{ $user->display_name }} ({{ $user->username }})
                                </label>
                            </div>
                            @endforeach
                        </div>

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
@stop
