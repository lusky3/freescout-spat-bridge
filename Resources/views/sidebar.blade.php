<div class="conv-sidebar-block">
    <div class="spatbridge" data-default="{{ $layoutDefault }}" data-switch="{{ $allowSwitch ? 1 : 0 }}">
        @if ($state === 'unavailable')
            <div class="panel-group accordion accordion-empty">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a data-toggle="collapse" href=".spatbridge-collapse-unavailable">
                                {{ __('ARL League') }}
                                <b class="caret"></b>
                            </a>
                        </h4>
                    </div>
                    <div class="spatbridge-collapse-unavailable panel-collapse collapse in">
                        <div class="panel-body">
                            <small class="text-help">{{ __('League info unavailable.') }}</small>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="spatbridge-stacked" @if ($layoutDefault !== 'stacked') hidden @endif>
                @include('spatbridge::sidebar.stacked')
            </div>
            <div class="spatbridge-tabbed" @if ($layoutDefault !== 'tabbed') hidden @endif>
                @include('spatbridge::sidebar.tabbed')
            </div>
        @endif
    </div>
</div>
