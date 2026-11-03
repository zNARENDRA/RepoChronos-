<!doctype html>
<html lang="en">
<head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
 <title>RepoChronos</title>
 <link rel="stylesheet" href="/bootstrap.min.css"><link rel="stylesheet" href="/ledger.css">
</head>
<body>
<header class="navbar px-4 py-3"><a class="navbar-brand text-white fw-bold" href="/">RepoChronos <small class="fw-normal">/ Repository health ledger</small></a>
 @auth <div class="d-flex align-items-center gap-3"><span>{{ auth()->user()->name }}</span><form method="post" action="/logout">@csrf<button class="btn btn-sm btn-outline-light">Log out</button></form></div> @endauth
</header>
<main class="container-fluid px-md-5 py-4">
 @if(session('notice'))<div class="alert alert-success" role="status">{{ session('notice') }}</div>@endif
 @if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
 @yield('content')
</main>
<footer class="px-5 py-3 text-secondary border-top">RepoChronos · All timestamps UTC · Complexity indicates maintainability risk.</footer>
</body></html>
