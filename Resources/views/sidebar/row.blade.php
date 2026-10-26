<li>
    <div>
        {{ $row->title() }}
        @if ($row->badge() !== null)
            <span class="pull-right {{ ['ok' => 'text-success', 'warn' => 'text-warning', 'bad' => 'text-danger', 'muted' => 'text-help'][$row->tone()] }}">{{ $row->badge() }}</span>
        @endif
    </div>
    @foreach ($row->lines() as $line)
        <div><small class="text-help">{{ $line }}</small></div>
    @endforeach
</li>
