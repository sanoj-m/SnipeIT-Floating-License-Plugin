<div class="form-group">
    <label for="pool_size">{{ trans('floating-licenses::floating.pool_size') }}</label>
    <input type="number" name="pool_size" id="pool_size" class="form-control" min="1"
           value="{{ old('pool_size', $config?->pool_size ?? 1) }}" required>
</div>
<div class="form-group">
    <label for="total_cost">{{ trans('floating-licenses::floating.total_cost') }}</label>
    <input type="number" step="0.01" name="total_cost" id="total_cost" class="form-control" min="0"
           value="{{ old('total_cost', $config?->total_cost) }}">
</div>
<div class="form-group">
    <label for="cost_mode">{{ trans('floating-licenses::floating.cost_mode') }}</label>
    <select name="cost_mode" id="cost_mode" class="form-control">
        <option value="pool_slot" @selected(old('cost_mode', $config?->cost_mode ?? config('floating-licenses.cost_mode')) === 'pool_slot')>
            {{ trans('floating-licenses::floating.cost_mode_pool_slot') }}
        </option>
        <option value="active_user" @selected(old('cost_mode', $config?->cost_mode) === 'active_user')>
            {{ trans('floating-licenses::floating.cost_mode_active_user') }}
        </option>
    </select>
</div>
<div class="form-group">
    <label>
        <input type="hidden" name="allow_over_allocation" value="0">
        <input type="checkbox" name="allow_over_allocation" value="1"
               @checked(old('allow_over_allocation', $config?->allow_over_allocation ?? config('floating-licenses.allow_over_allocation')))>
        {{ trans('floating-licenses::floating.allow_over_allocation') }}
    </label>
</div>
{{-- Durations are optional: blank = never expires / no idle reclamation. --}}
<div class="form-group">
    <label for="lease_duration_minutes">{{ trans('floating-licenses::floating.lease_duration_minutes') }}</label>
    <input type="number" name="lease_duration_minutes" id="lease_duration_minutes" class="form-control" min="1"
           value="{{ old('lease_duration_minutes', $config?->lease_duration_minutes) }}">
</div>
<div class="form-group">
    <label for="idle_timeout_minutes">{{ trans('floating-licenses::floating.idle_timeout_minutes') }}</label>
    <input type="number" name="idle_timeout_minutes" id="idle_timeout_minutes" class="form-control" min="1"
           value="{{ old('idle_timeout_minutes', $config?->idle_timeout_minutes) }}">
</div>
