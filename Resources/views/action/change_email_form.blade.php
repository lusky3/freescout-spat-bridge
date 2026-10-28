{{-- AJAX-loaded into the action pop-up before a change of email is previewed; no inline script or handlers, Public/js/actions.js wires it up. --}}
<div class="spatbridge-email-form"
     data-preview-url="{{ route('spatbridge.action.preview') }}"
     data-feature="{{ $feature }}"
     data-action="{{ $action }}"
     data-params="{{ $paramsJson }}"
     data-conversation-id="{{ $conversationId }}">
    <p>{{ __('Current email') }}: <strong>{{ $oldEmail }}</strong></p>
    <div class="form-group">
        <label for="spatbridge-new-email">{{ __('New email') }}</label>
        <input type="email" id="spatbridge-new-email" class="form-control spatbridge-new-email" autocomplete="off">
    </div>

    <p class="alert alert-danger spatbridge-email-error" role="alert" hidden></p>

    <div class="form-group">
        <button type="button" class="btn btn-primary spatbridge-email-preview">{{ __('Preview') }}</button>
        <button type="button" class="btn btn-link" data-dismiss="modal">{{ __('Cancel') }}</button>
    </div>
</div>
