@php
  $code = $exception->getStatusCode();
  [$heading, $description] = match ($code) {
    502, 504 => ['Service temporarily unavailable', 'A service did not respond in time. Please try again in a moment.'],
    503 => ['Temporarily unavailable', 'Bambodia is temporarily unavailable. Please check back shortly.'],
    default => ['Something went wrong', 'Bambodia could not load this page. Try again in a moment or contact support if the issue continues.'],
  };
@endphp
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>{{ $heading }} · Bambodia</title>
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="stylesheet" href="/css/error-404.css?v=2">
  <script>
    try {
      const theme = localStorage.getItem('bambodia-theme');
      if (theme === 'light' || theme === 'dark') document.documentElement.dataset.theme = theme;
    } catch {}
  </script>
</head>
<body class="site-error-standalone">
  <header class="site-error-header">
    <a class="site-error-brand" href="/" aria-label="Bambodia home">
      <img class="site-error-brand-light" src="/images/bambodia-logo.webp" alt="Bambodia" width="550" height="144">
      <img class="site-error-brand-dark" src="/images/bambodia-logo-dark.svg" alt="Bambodia" width="550" height="144">
    </a>
    <a href="/support-center">Support Center</a>
  </header>
  <main class="site-not-found" aria-labelledby="page-title">
    @include('errors.status-mark', ['code' => $code])
    <h1 id="page-title">{{ $heading }}</h1>
    <p>{{ $description }}</p>
    <nav class="site-not-found-actions" aria-label="Where to go next">
      <a class="btn primary" href="/">Back to homepage</a>
      <a class="btn subtle" href="/support-center">Get help</a>
    </nav>
  </main>
</body>
</html>
