<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Authorize Bambodia Manager</title>
    <style>
        :root{color-scheme:light dark;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        body{min-height:100vh;display:grid;place-items:center;margin:0;background:light-dark(#f5f7fb,#12151b);color:light-dark(#1c2431,#f5f6fa)}
        main{width:min(460px,calc(100vw - 40px));border:1px solid light-dark(#dce2e9,#3f4651);background:light-dark(white,#20252d);border-radius:18px;padding:28px;box-shadow:0 18px 60px #0001}
        h1{font-size:1.45rem;margin:12px 0}.sub{color:light-dark(#4f5c6f,#b6c0cb);line-height:1.6}
        ul{line-height:1.85;padding-left:20px}.client{font-weight:700;overflow-wrap:anywhere}
        .actions{display:flex;gap:12px;margin-top:24px}.actions form{flex:1}
        button{width:100%;padding:12px;border-radius:10px;border:1px solid light-dark(#cbd5e1,#5a6472);font:inherit;cursor:pointer}
        .allow{background:#194cb8;color:#fff;border-color:#194cb8}.note{font-size:.83rem;color:light-dark(#677386,#a7b0bf)}
    </style>
</head>
<body>
<main>
    <div class="note">Bambodia • Secure account authorization</div>
    <h1>Connect Bambodia Manager</h1>
    <p class="sub">The application <span class="client">{{ $client->name }}</span> requests permission to access your Bambodia administrator account.</p>
    <p class="note">Grant only the access you intend. You can revoke the connection later.</p>
    <ul>
      @foreach ($scopes as $scope)
        <li>{{ $scope->description }}</li>
      @endforeach
    </ul>
    <div class="actions">
      <form method="POST" action="{{ route('passport.authorizations.deny') }}">
        @csrf @method('DELETE')
        <input type="hidden" name="state" value="{{ $request->state }}">
        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit">Cancel</button>
      </form>
      <form method="POST" action="{{ route('passport.authorizations.approve') }}">
        @csrf
        <input type="hidden" name="state" value="{{ $request->state }}">
        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit" class="allow">Authorize access</button>
      </form>
    </div>
</main>
</body>
</html>
