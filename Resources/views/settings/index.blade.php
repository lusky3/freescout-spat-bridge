@if (session('success'))
    <div class="alert alert-success alert-dismissible" role="alert" aria-live="polite">
        <button type="button" class="close" data-dismiss="alert" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button>
        {{ session('success') }}
    </div>
@endif

<h3>{{ __('ARL League') }}</h3>
<p class="text-muted">{{ __('Connects FreeScout to SportsPress Admin Tools on your WordPress site, so league info shows in the conversation sidebar.') }}</p>

<form method="post" action="{{ route('spatbridge_save_settings') }}" class="form-horizontal">
    {{ csrf_field() }}

    <h4>1 &middot; {{ __('Connection') }}</h4>

    <div class="form-group {{ $errors->has('wp_base_url') ? 'has-error' : '' }}">
        <label for="spatbridge_wp_base_url" class="col-sm-3 control-label">{{ __('WordPress site URL') }}</label>
        <div class="col-sm-6">
            <input type="text" id="spatbridge_wp_base_url" name="wp_base_url" class="form-control"
                   placeholder="https://example.com" value="{{ old('wp_base_url', $wpBaseUrl) }}" required>
            @if ($errors->has('wp_base_url'))
                <span class="help-block">{{ $errors->first('wp_base_url') }}</span>
            @endif
        </div>
    </div>

    <div class="form-group {{ $errors->has('shared_secret') ? 'has-error' : '' }}">
        <label for="spatbridge_shared_secret" class="col-sm-3 control-label">{{ __('Shared secret') }}</label>
        <div class="col-sm-6">
            <input type="password" id="spatbridge_shared_secret" name="shared_secret" class="form-control"
                   autocomplete="off" value="{{ $hasSecret ? str_repeat('•', 16) : '' }}">
            <label style="font-weight: normal; margin-top: 6px;">
                <input type="checkbox" onclick="document.getElementById('spatbridge_shared_secret').type = this.checked ? 'text' : 'password';">
                {{ __('Show') }}
            </label>
            <span class="help-block">{{ __('Must match the value in WP → SportsPress Admin Tools → FreeScout. Minimum 32 characters. Leave the bullets in place to keep the existing secret.') }}</span>
            @if ($errors->has('shared_secret'))
                <span class="help-block">{{ $errors->first('shared_secret') }}</span>
            @endif
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
            <button type="button" id="spatbridge-test" class="btn btn-default"
                    data-url="{{ route('spatbridge_test_connection') }}">{{ __('Test connection') }}</button>
            <span id="spatbridge-test-result" role="status" aria-live="polite" style="margin-left: 10px;"></span>
            <span class="help-block">{{ __('Tests the saved URL and secret, so save first if you changed them.') }}</span>
        </div>
    </div>

    <h4>2 &middot; {{ __('Modules') }}</h4>

    <table class="table table-condensed">
        <thead>
            <tr>
                <th>{{ __('Module') }}</th>
                <th>{{ __('Shown') }}</th>
                <th>{{ __('WP side') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($features as $feature)
                <tr class="spatbridge-feature" data-providers="{{ json_encode($feature['providerKeys']) }}">
                    <td><strong>{{ $feature['label'] }}</strong></td>
                    <td>
                        <input type="checkbox" name="features[{{ $feature['key'] }}]" value="1"
                               aria-label="{{ __('Show :module', ['module' => $feature['label']]) }}"
                               {{ $feature['enabled'] ? 'checked' : '' }}>
                    </td>
                    <td class="spatbridge-wp-side text-muted">{{ __('Run Test connection to check') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="help-block">{{ __('Turning a module off here hides it for everyone.') }}</p>

    <h4>3 &middot; {{ __('Sidebar') }}</h4>

    <div class="form-group {{ $errors->has('layout_default') ? 'has-error' : '' }}">
        <label class="col-sm-3 control-label">{{ __('Default layout') }}</label>
        <div class="col-sm-6">
            <label class="radio-inline">
                <input type="radio" name="layout_default" value="stacked" {{ old('layout_default', $layoutDefault) === 'stacked' ? 'checked' : '' }}>
                {{ __('Stacked cards') }}
            </label>
            <label class="radio-inline">
                <input type="radio" name="layout_default" value="tabbed" {{ old('layout_default', $layoutDefault) === 'tabbed' ? 'checked' : '' }}>
                {{ __('One card with tabs') }}
            </label>
            <span class="help-block">{{ __('Default for every agent.') }}</span>
            @if ($errors->has('layout_default'))
                <span class="help-block">{{ $errors->first('layout_default') }}</span>
            @endif
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-3 control-label" for="spatbridge_allow_agent_switch">{{ __('Let agents switch') }}</label>
        <div class="col-sm-6">
            <div class="checkbox">
                <label>
                    <input type="checkbox" id="spatbridge_allow_agent_switch" name="allow_agent_switch" value="1" {{ $allowSwitch ? 'checked' : '' }}>
                    {{ __('Adds a switch to the card header. The choice is remembered per browser.') }}
                </label>
            </div>
        </div>
    </div>

    <div class="form-group {{ $errors->has('mailboxes.*') ? 'has-error' : '' }}">
        <label class="col-sm-3 control-label" for="spatbridge_mailboxes">{{ __('Show in mailboxes') }}</label>
        <div class="col-sm-6">
            <select id="spatbridge_mailboxes" name="mailboxes[]" class="form-control" multiple size="{{ min(max(count($mailboxes), 3), 8) }}">
                @foreach ($mailboxes as $mailbox)
                    <option value="{{ $mailbox['id'] }}" {{ in_array($mailbox['id'], $selectedMailboxes, true) ? 'selected' : '' }}>{{ $mailbox['name'] }}</option>
                @endforeach
            </select>
            <span class="help-block">{{ __('Leave empty to show in all mailboxes.') }}</span>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-3 control-label">{{ __('Card order') }}</label>
        <div class="col-sm-6">
            <ol id="spatbridge-feature-order" style="padding-left: 20px;">
                @foreach ($featureOrder as $key => $label)
                    <li style="margin-bottom: 4px;">
                        <input type="hidden" name="feature_order[]" value="{{ $key }}">
                        {{ $label }}
                        <button type="button" class="btn btn-default btn-xs spatbridge-move" data-dir="-1" aria-label="{{ __('Move up') }}">&uarr;</button>
                        <button type="button" class="btn btn-default btn-xs spatbridge-move" data-dir="1" aria-label="{{ __('Move down') }}">&darr;</button>
                    </li>
                @endforeach
            </ol>
            <span class="help-block">{{ __('Order of the cards (stacked) or tabs (tabbed).') }}</span>
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>

<script>
    (function () {
        var btn = document.getElementById('spatbridge-test');
        var out = document.getElementById('spatbridge-test-result');

        function paintWpSide(providers) {
            var rows = document.querySelectorAll('.spatbridge-feature');
            Array.prototype.forEach.call(rows, function (row) {
                var needed = JSON.parse(row.getAttribute('data-providers') || '[]');
                var cell = row.querySelector('.spatbridge-wp-side');
                var ok = needed.every(function (key) { return providers.indexOf(key) !== -1; });
                cell.className = 'spatbridge-wp-side ' + (ok ? 'text-success' : 'text-warning');
                cell.textContent = ok ? '✓ ' + needed.join(', ') : '⚠ {{ __('not active in WordPress') }}';
            });
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            out.className = 'text-muted';
            out.textContent = '{{ __('Testing...') }}';
            fetch(btn.getAttribute('data-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            }).then(function (r) { return r.json(); }).then(function (res) {
                out.className = res.ok ? 'text-success' : 'text-danger';
                out.textContent = (res.ok ? '✓ ' : '✗ ') + res.message;
                if (res.ok) { paintWpSide(res.providers || []); }
            }).catch(function () {
                out.className = 'text-danger';
                out.textContent = '✗ {{ __('The test request failed.') }}';
            }).then(function () { btn.disabled = false; });
        });

        document.getElementById('spatbridge-feature-order').addEventListener('click', function (e) {
            var move = e.target.closest('.spatbridge-move');
            if (!move) { return; }
            var li = move.closest('li');
            var dir = parseInt(move.getAttribute('data-dir'), 10);
            if (dir < 0 && li.previousElementSibling) {
                li.parentNode.insertBefore(li, li.previousElementSibling);
            } else if (dir > 0 && li.nextElementSibling) {
                li.parentNode.insertBefore(li.nextElementSibling, li);
            }
        });
    })();
</script>
