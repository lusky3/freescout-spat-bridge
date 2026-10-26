{{-- AJAX-loaded into the action pop-up: no inline script or handlers; Public/js/actions.js wires it up. --}}
<div class="spatbridge-action-preview"
     data-run-url="{{ route('spatbridge.action.run') }}"
     data-feature="{{ $feature }}"
     data-action="{{ $action }}"
     data-params="{{ $paramsJson }}"
     data-conversation-id="{{ $conversationId }}"
     data-idempotency-key="{{ $idempotencyKey }}">
    <p><strong>{{ $summary }}</strong></p>

    @if ($changes !== [])
        <ul>
            @foreach ($changes as $change)
                <li>{{ $change }}</li>
            @endforeach
        </ul>
    @endif

    @if ($confirms !== [])
        <div class="form-group">
            @foreach ($confirms as $confirm)
                <div class="checkbox">
                    <label>
                        <input type="checkbox" class="spatbridge-action-confirm" value="{{ $confirm['code'] }}">
                        {{ $confirm['message'] }}
                    </label>
                </div>
            @endforeach
        </div>
    @endif

    <p class="alert alert-danger spatbridge-action-error" role="alert" hidden></p>

    <div class="form-group">
        <button type="button" class="btn btn-primary spatbridge-action-run"
                data-running-text="{{ __('Running…') }}" @if ($confirms !== []) disabled @endif>{{ __('Confirm') }}</button>
        <button type="button" class="btn btn-link" data-dismiss="modal">{{ __('Cancel') }}</button>
    </div>
</div>
