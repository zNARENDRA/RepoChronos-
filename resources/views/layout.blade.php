<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Workspace') · RepoChronos</title>
    <link rel="stylesheet" href="/bootstrap.min.css">
    <link rel="stylesheet" href="/ledger.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="mast">
    <div class="frame mast-inner">
        <a class="brand" href="/"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>RepoChronos</a>
        <div class="tools">
            <label class="motion-control">
                <input type="checkbox" id="pause-motion" aria-label="Pause page animations">
                <span class="motion-icon" aria-hidden="true"></span><span>Motion</span>
            </label>
            @auth
                <span class="identity">{{ auth()->user()->name }}</span>
                <form method="post" action="/logout">@csrf<button class="text-button">Log out</button></form>
            @endauth
        </div>
    </div>
</header>
<main class="frame" id="main">
    @if(session('notice'))<div class="alert alert-success" role="status">{{ session('notice') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif
    @yield('content')
</main>
<footer class="frame footer"><span>Repository health ledger</span><span>UTC</span></footer>
</body>
</html>
