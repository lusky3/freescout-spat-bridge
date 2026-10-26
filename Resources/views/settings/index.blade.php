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
                <input type="checkbox" id="spatbridge_show_secret">
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
                <th>{{ __('Who can run actions') }}</th>
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
                    <td>
                        <select name="actors[{{ $feature['key'] }}][mode]" class="form-control input-sm"
                                aria-label="{{ __('Who can run :module actions', ['module' => $feature['label']]) }}">
                            @foreach (['admins' => __('Admins'), 'users' => __('Admins + Users'), 'selected' => __('Selected people')] as $mode => $modeLabel)
                                <option value="{{ $mode }}" {{ $feature['actorMode'] === $mode ? 'selected' : '' }}>{{ $modeLabel }}</option>
                            @endforeach
                        </select>
                        <select name="actors[{{ $feature['key'] }}][user_ids][]" class="form-control input-sm" multiple
                                size="{{ min(max(count($users), 3), 6) }}" style="margin-top: 4px;"
                                aria-label="{{ __('Selected people for :module', ['module' => $feature['label']]) }}">
                            @foreach ($users as $user)
                                <option value="{{ $user['id'] }}" {{ in_array($user['id'], $feature['actorUserIds'], true) ? 'selected' : '' }}>{{ $user['name'] }}</option>
                            @endforeach
                        </select>
                        <span class="help-block">{{ __('The list is used with "Selected people". Admins can always run actions.') }}</span>
                    </td>
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

    <h4>4 &middot; {{ __('Events from WordPress') }} <small>{{ __('SPAT tells FreeScout when something changes') }}</small></h4>

    <div class="form-group">
        <label for="spatbridge_events_url" class="col-sm-3 control-label">{{ __('Webhook URL for WP') }}</label>
        <div class="col-sm-6">
            <div class="input-group">
                <input type="text" id="spatbridge_events_url" class="form-control" readonly value="{{ $eventsUrl }}">
                <span class="input-group-btn">
                    <button type="button" id="spatbridge-copy-events-url" class="btn btn-default">{{ __('Copy') }}</button>
                </span>
            </div>
            <span id="spatbridge-copy-result" role="status" aria-live="polite" class="text-success"></span>
            <span class="help-block">{{ __('Paste into WordPress. Signed with the shared secret above.') }}</span>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-3 control-label">{{ __('When an event matches a customer with an open ticket') }}</label>
        <div class="col-sm-6">
            <div class="checkbox">
                <label>
                    <input type="checkbox" name="events_notes" value="1" {{ (count($errors) > 0 ? old('events_notes') !== null : $events['notes']) ? 'checked' : '' }}>
                    {{ __('Add an internal note') }}
                </label>
            </div>
            <div class="checkbox">
                <label>
                    <input type="checkbox" name="events_tags" value="1" {{ (count($errors) > 0 ? old('events_tags') !== null : $events['tags']) ? 'checked' : '' }}>
                    {{ __('Apply a tag') }}
                </label>
            </div>
            @if (!$events['tagsAvailable'])
                <p class="text-warning">{{ __('Tags module not active — tags will be skipped.') }}</p>
            @endif
        </div>
    </div>

    <table class="table table-condensed">
        <thead>
            <tr>
                <th>{{ __('Event') }}</th>
                <th>{{ __('Tag') }}</th>
                <th>{{ __('No open ticket → create one?') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($events['types'] as $type)
                <tr class="{{ $errors->has('event_tag.' . $type['key']) ? 'has-error' : '' }}">
                    <td>{{ $type['label'] }}</td>
                    <td>
                        <input type="text" name="event_tag[{{ $type['key'] }}]" class="form-control input-sm" maxlength="100"
                               value="{{ old('event_tag.' . $type['key'], $type['tag']) }}"
                               aria-label="{{ __('Tag for :event', ['event' => $type['label']]) }}">
                        @if ($errors->has('event_tag.' . $type['key']))
                            <span class="help-block">{{ $errors->first('event_tag.' . $type['key']) }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($type['canCreate'])
                            <input type="checkbox" name="event_create[{{ $type['key'] }}]" value="1"
                                   aria-label="{{ __('Create a ticket for :event when there is no open ticket', ['event' => $type['label']]) }}"
                                   {{ (count($errors) > 0 ? old('event_create.' . $type['key']) !== null : $type['create']) ? 'checked' : '' }}>
                        @else
                            <span class="text-muted" title="{{ __('Resolves an earlier event, so it never opens a ticket.') }}">&mdash;</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="help-block">{{ __('Leave a tag empty to apply none. Matched, expired and claimed events remove the tag their opening event adds.') }}</p>
    <p class="help-block">{{ __('An event with no email creates a ticket for a customer with no email address (named after the sender).') }}</p>

    <div class="form-group {{ $errors->has('events_mailbox_id') ? 'has-error' : '' }}">
        <label for="spatbridge_events_mailbox_id" class="col-sm-3 control-label">{{ __('New tickets go to') }}</label>
        <div class="col-sm-6">
            <select id="spatbridge_events_mailbox_id" name="events_mailbox_id" class="form-control">
                <option value="">{{ __('Choose a mailbox') }}</option>
                @foreach ($mailboxes as $mailbox)
                    <option value="{{ $mailbox['id'] }}" {{ (int) old('events_mailbox_id', $events['mailboxId']) === $mailbox['id'] ? 'selected' : '' }}>{{ $mailbox['name'] }}</option>
                @endforeach
            </select>
            <span class="help-block">{{ __('Required when any create switch is on. Nothing is emailed to the customer unless an agent replies.') }}</span>
            @if ($errors->has('events_mailbox_id'))
                <span class="help-block">{{ $errors->first('events_mailbox_id') }}</span>
            @endif
        </div>
    </div>

    <div class="form-group {{ $errors->has('events_note_user_id') ? 'has-error' : '' }}">
        <label for="spatbridge_events_note_user_id" class="col-sm-3 control-label">{{ __('Post notes as') }}</label>
        <div class="col-sm-6">
            <select id="spatbridge_events_note_user_id" name="events_note_user_id" class="form-control">
                <option value="0">{{ __('First active admin') }}</option>
                @foreach ($users as $user)
                    <option value="{{ $user['id'] }}" {{ (int) old('events_note_user_id', $events['noteUserId']) === $user['id'] ? 'selected' : '' }}>{{ $user['name'] }}</option>
                @endforeach
            </select>
            @if ($errors->has('events_note_user_id'))
                <span class="help-block">{{ $errors->first('events_note_user_id') }}</span>
            @endif
        </div>
    </div>

    <p class="help-block">{{ __('Tags are ordinary FreeScout tags, so your existing Workflows rules can act on them.') }}</p>

    <div class="form-group">
        <label class="col-sm-3 control-label">{{ __('Last event received') }}</label>
        <div class="col-sm-6">
            <p class="form-control-static">
                @if ($events['tableMissing'])
                    <span class="text-warning">{{ __('Events table missing — run `php artisan migrate` (or reinstall the module).') }}</span>
                @elseif ($events['lastEvent'])
                    {{ $events['lastEvent']['time'] }}
                    <span class="text-muted">&middot; {{ $events['lastEvent']['type'] }} &middot; {{ $events['lastEvent']['email'] }} &middot; {{ $events['lastEvent']['outcome'] }}</span>
                @else
                    <span class="text-muted">{{ __('None yet') }}</span>
                @endif
                &middot; <a href="{{ route('spatbridge.events.log') }}">{{ __('view log') }}</a>
            </p>
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>

{{-- FreeScout's CSP allows inline scripts only with its nonce, and no inline on*= handlers at all. --}}
<script {!! \Helper::cspNonceAttr() !!}>
    (function () {
        var T = {!! json_encode([
            'copied' => __('Copied'),
            'copy' => __('Press Ctrl+C to copy.'),
            'inactive' => __('not active in WordPress'),
            'testing' => __('Testing...'),
            'failed' => __('The test request failed.'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
        var btn = document.getElementById('spatbridge-test');
        var out = document.getElementById('spatbridge-test-result');
        var secret = document.getElementById('spatbridge_shared_secret');

        document.getElementById('spatbridge_show_secret').addEventListener('change', function () {
            secret.type = this.checked ? 'text' : 'password';
        });

        var copyBtn = document.getElementById('spatbridge-copy-events-url');
        var copyOut = document.getElementById('spatbridge-copy-result');
        copyBtn.addEventListener('click', function () {
            var field = document.getElementById('spatbridge_events_url');
            var done = function () { copyOut.textContent = '✓ ' + T.copied; };
            field.focus();
            field.select();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(field.value).then(done, function () {
                    copyOut.textContent = T.copy;
                });
            } else {
                try { document.execCommand('copy'); done(); } catch (e) { copyOut.textContent = T.copy; }
            }
        });

        function paintWpSide(providers) {
            var rows = document.querySelectorAll('.spatbridge-feature');
            Array.prototype.forEach.call(rows, function (row) {
                var needed = JSON.parse(row.getAttribute('data-providers') || '[]');
                var cell = row.querySelector('.spatbridge-wp-side');
                var ok = needed.every(function (key) { return providers.indexOf(key) !== -1; });
                cell.className = 'spatbridge-wp-side ' + (ok ? 'text-success' : 'text-warning');
                cell.textContent = ok ? '✓ ' + needed.join(', ') : '⚠ ' + T.inactive;
            });
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            out.className = 'text-muted';
            out.textContent = T.testing;
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
                out.textContent = '✗ ' + T.failed;
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
