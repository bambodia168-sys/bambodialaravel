<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $copy['subject'] }}</title>
</head>
<body style="margin:0;background:#eef3f9;color:#16253c;font-family:Arial,Helvetica,sans-serif">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef3f9">
  <tr><td align="center" style="padding:28px 12px">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border:1px solid #d5e0ef;border-radius:16px">
      <tr><td style="padding:25px 28px;background:#111f36;color:#fff;border-radius:15px 15px 0 0;font-size:22px;font-weight:bold">
        <span style="color:#ff9b51">{{ config('app.name') }}</span>
      </td></tr>
      <tr><td style="padding:30px 28px">
        <h1 style="margin:0 0 20px;font-size:26px;line-height:1.25;color:#16253c">{{ $copy['heading'] }}</h1>
        @foreach(preg_split('/\R{2,}/', $copy['body']) as $paragraph)
          <p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#35465e">{!! nl2br(e($paragraph)) !!}</p>
        @endforeach
        @if($actionUrl && $copy['button'] !== '')
          <p style="margin:24px 0 0"><a href="{{ $actionUrl }}" style="display:inline-block;padding:13px 20px;border-radius:9px;background:#b63908;color:#fff;font-size:16px;font-weight:bold;text-decoration:none">{{ $copy['button'] }}</a></p>
        @endif
      </td></tr>
      <tr><td style="border-top:1px solid #d5e0ef;padding:18px 28px;font-size:13px;line-height:1.6;color:#475b76">
        <a href="{{ config('app.url') }}" style="color:#334f77">{{ config('app.name') }}</a>
        @if($unsubscribeUrl)
          <br>You're receiving this because you signed up for marketplace news.
          <a href="{{ $unsubscribeUrl }}" style="color:#334f77">Unsubscribe</a>
        @endif
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
