{!! $copy['heading'] !!}

{!! $copy['body'] !!}
@if($actionUrl && $copy['button'] !== '')

{!! $copy['button'] !!}
{!! $actionUrl !!}
@endif

{!! config('app.url') !!}
@if($unsubscribeUrl)

Unsubscribe from marketplace news
{!! $unsubscribeUrl !!}
@endif
