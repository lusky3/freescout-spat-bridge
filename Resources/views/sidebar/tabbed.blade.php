<div class="panel-group accordion accordion-empty">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h4 class="panel-title">
                <a data-toggle="collapse" href=".spatbridge-collapse-tabbed">
                    {{ __('ARL League') }}
                    <b class="caret"></b>
                </a>
                @if ($allowSwitch)
                    <a href="#" class="spatbridge-switch pull-right" title="{{ __('Switch layout') }}">&#8644;</a>
                @endif
            </h4>
        </div>
        <div class="spatbridge-collapse-tabbed panel-collapse collapse in">
            <div class="panel-body">
                @if ($summary !== null)
                    <div><small class="text-help">{{ $summary }}</small></div>
                @endif
                <ul class="nav nav-pills spatbridge-tabs">
                    @foreach ($panels as $panel)
                        <li @if ($panel->key() === $activeTab) class="active" @endif>
                            <a href="#" class="spatbridge-tab" data-tab="{{ $panel->key() }}">{{ $panel->label() }}</a>
                        </li>
                    @endforeach
                </ul>
                @foreach ($panels as $panel)
                    <div class="spatbridge-tabpane" data-tab="{{ $panel->key() }}" @if ($panel->key() !== $activeTab) hidden @endif>
                        <ul class="sidebar-block-list">
                            @foreach ($panel->rows() as $row)
                                @include('spatbridge::sidebar.row', ['row' => $row])
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
