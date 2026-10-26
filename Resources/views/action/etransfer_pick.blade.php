{{-- AJAX-loaded into the action pop-up when a match still needs an order or a transfer; no inline script or handlers, Public/js/actions.js wires it up. --}}
<div class="spatbridge-pick"
     data-mode="{{ $mode }}"
     data-timezone="{{ $timezone ?? 'UTC' }}"
     data-preview-url="{{ route('spatbridge.action.preview') }}"
     data-feature="{{ $feature }}"
     data-action="{{ $action }}"
     data-params="{{ $paramsJson }}"
     data-conversation-id="{{ $conversationId }}">
    <div class="form-group spatbridge-search">
        @if ($mode === 'order')
            <label>{{ __('Order number') }}</label>
            <div class="input-group">
                <input type="text" class="form-control spatbridge-pick-input" inputmode="numeric"
                       data-param="order_id" autocomplete="off">
                <span class="input-group-btn">
                    <button type="button" class="btn btn-default spatbridge-pick-find"
                            data-url="{{ route('spatbridge.action.query') }}?feature={{ $feature }}&amp;query=order&amp;conversation_id={{ $conversationId }}">{{ __('Find') }}</button>
                </span>
            </div>
        @else
            <label>{{ __('Search e-Transfers') }}</label>
            <div class="input-group">
                <input type="text" class="form-control spatbridge-pick-input"
                       data-param="q" autocomplete="off" placeholder="{{ __('Name, reference or amount') }}">
                <span class="input-group-btn">
                    <button type="button" class="btn btn-default spatbridge-pick-find"
                            data-url="{{ route('spatbridge.action.query') }}?feature={{ $feature }}&amp;query=search&amp;conversation_id={{ $conversationId }}">{{ __('Search') }}</button>
                </span>
            </div>
        @endif
        <ul class="list-unstyled spatbridge-search-results"></ul>
    </div>

    <p class="alert alert-danger spatbridge-pick-error" role="alert" hidden></p>

    <div class="form-group">
        <button type="button" class="btn btn-link" data-dismiss="modal">{{ __('Cancel') }}</button>
    </div>
</div>
