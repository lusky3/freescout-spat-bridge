<li>
    @if ($row->title() !== '' || $row->badge() !== null)
        <div>
            {{ $row->title() }}
            @if ($row->badge() !== null)
                <span class="pull-right {{ ['ok' => 'text-success', 'warn' => 'text-warning', 'bad' => 'text-danger', 'muted' => 'text-help'][$row->tone()] }}">{{ $row->badge() }}</span>
            @endif
        </div>
    @endif
    @foreach ($row->lines() as $line)
        <div><small class="text-help">{{ $line }}</small></div>
    @endforeach
    @if ($row->actions() !== [])
        <div class="spatbridge-actions">
            @foreach ($row->actions() as $action)
                <a href="{{ route('spatbridge.action.preview') }}?{{ $action->query() }}&conversation_id={{ $conversationId ?? 0 }}"
                   data-trigger="modal" data-modal-title="{{ $action->label() }}" data-modal-no-footer="true"
                   data-modal-on-show="spatbridgeActionInit" class="spatbridge-action"><small>{{ $action->label() }}</small></a>
            @endforeach
        </div>
    @endif
</li>
