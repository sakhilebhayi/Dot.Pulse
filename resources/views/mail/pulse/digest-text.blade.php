{{ $digest['subject'] }}

{{ $digest['intro'] }}

@foreach ($digest['items'] as $item)
{{ strtoupper($item['kind_label']) }}{{ $item['place'] ? ' - '.$item['place'] : '' }}
{{ $item['title'] }}
@if ($item['from'])From {{ $item['from'] }}
@endif
Why you are seeing this: {{ $item['reason'] }}
{{ $item['url'] }}

@endforeach
@if ($digest['more'] > 0)
{{ $digest['more'] }} more {{ $digest['more'] === 1 ? 'match is' : 'matches are' }} waiting on the Floor.

@endif
Open the Floor: {{ $digest['floor_url'] }}

You get this because you asked for a digest. It is only sent when there is something real in it.
Change how often: {{ $digest['manage_url'] }}
Stop the digest: {{ $digest['unsubscribe_url'] }}
