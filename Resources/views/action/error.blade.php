{{-- AJAX-loaded into the action pop-up when the preview can't be shown. --}}
<div class="spatbridge-action-failed">
    <p class="alert alert-danger" role="alert">{{ $message }}</p>
    <div class="form-group">
        <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('Close') }}</button>
    </div>
</div>
