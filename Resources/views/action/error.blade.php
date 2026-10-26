{{-- AJAX-loaded into the action pop-up when the preview can't be shown. A preview requested from a form (data-spatbridge-error) shows the message in that form instead; see Public/js/actions.js. --}}
<div class="spatbridge-action-failed" data-spatbridge-error="1">
    <p class="alert alert-danger spatbridge-action-failed-message" role="alert">{{ $message }}</p>
    <div class="form-group">
        <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('Close') }}</button>
    </div>
</div>
