@extends('layouts.app')

@section('title', __('ARL League events'))

@section('content')
<div class="container">
    <h3>{{ __('Events from WordPress') }}</h3>
    <p class="text-muted">
        {{ __('The latest 100 events, newest first.') }}
        <a href="{{ route('settings', ['section' => 'spatbridge']) }}">{{ __('Back to settings') }}</a>
    </p>

    @if (count($events) === 0)
        <p class="alert alert-info">{{ __('No events received yet.') }}</p>
    @else
        <table class="table table-condensed table-striped">
            <thead>
                <tr>
                    <th>{{ __('Time') }}</th>
                    <th>{{ __('Event') }}</th>
                    <th>{{ __('Email') }}</th>
                    <th>{{ __('Outcome') }}</th>
                    <th>{{ __('Tickets') }}</th>
                    <th>{{ __('Detail') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($events as $event)
                    <tr>
                        <td>{{ $event['time'] }}</td>
                        <td>{{ $event['type'] }}</td>
                        <td>{{ $event['email'] }}</td>
                        <td>{{ $event['outcome'] }}</td>
                        <td>
                            @foreach ($event['conversationIds'] as $conversationId)
                                <a href="{{ route('conversations.view', ['id' => $conversationId]) }}">#{{ $conversationId }}</a>
                            @endforeach
                        </td>
                        <td>{{ $event['detail'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
