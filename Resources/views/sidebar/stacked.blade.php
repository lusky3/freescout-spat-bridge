@foreach ($panels as $panel)
    <div class="panel-group accordion accordion-empty">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4 class="panel-title">
                    <a data-toggle="collapse" href=".spatbridge-collapse-{{ $panel->key() }}">
                        {{ $panel->label() }}
                        <b class="caret"></b>
                    </a>
                    @if ($allowSwitch)
                        <a href="#" class="spatbridge-switch pull-right" title="{{ __('Switch layout') }}">&#8644;</a>
                    @endif
                </h4>
            </div>
            <div class="spatbridge-collapse-{{ $panel->key() }} panel-collapse collapse in">
                <div class="panel-body">
                    <ul class="sidebar-block-list">
                        @foreach ($panel->rows() as $row)
                            @include('spatbridge::sidebar.row', ['row' => $row])
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endforeach
