{{-- AJAX-loaded into the action pop-up before an offer is previewed; no inline script or handlers, Public/js/actions.js wires it up. --}}
<div class="spatbridge-offer-form"
     data-preview-url="{{ route('spatbridge.action.preview') }}"
     data-feature="{{ $feature }}"
     data-action="{{ $action }}"
     data-params="{{ $paramsJson }}"
     data-conversation-id="{{ $conversationId }}"
     data-invalid-text="{{ __('Enter a number of hours from 1 to 720.') }}">
    <div class="form-group">
        <label for="spatbridge-offer-hours">{{ __('Hours to accept') }}</label>
        <input type="number" id="spatbridge-offer-hours" class="form-control spatbridge-offer-hours" min="1" max="720" step="1"
               value="{{ $defaultHours }}">
    </div>
    <div class="form-group">
        <label for="spatbridge-offer-message">{{ __('Message to the player (optional)') }}</label>
        <textarea id="spatbridge-offer-message" class="form-control spatbridge-offer-message" rows="3" maxlength="500"></textarea>
    </div>

    <p class="alert alert-danger spatbridge-offer-error" role="alert" hidden></p>

    <div class="form-group">
        <button type="button" class="btn btn-primary spatbridge-offer-preview">{{ __('Preview') }}</button>
        <button type="button" class="btn btn-link" data-dismiss="modal">{{ __('Cancel') }}</button>
    </div>
</div>
