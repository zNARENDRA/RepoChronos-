@extends('layout')
@section('content')
<p class="eyebrow">WORKSPACE</p><h1>Repositories</h1>
<p>Your repository memberships control access to history and updates.</p>
<section class="card p-4 my-4"><h2>Connect GitHub</h2>
<form class="d-flex gap-3" method="post" action="/repositories">@csrf
<label class="flex-grow-1">Repository URL or owner/name<input class="form-control" name="repository" placeholder="octocat/Hello-World" required></label>
<button class="btn btn-success align-self-end">Connect</button></form></section>
@forelse($repos as $repo)
<a class="card p-4 mb-3 text-decoration-none" href="/r/{{ $repo->id }}"><strong>{{ $repo->full_name }}</strong><span>{{ $repo->role }} · {{ $repo->source_key==='sample'?'Offline demo':'GitHub' }}</span></a>
@empty<p>No repositories yet. Connect GitHub or ask an owner to add your registered email.</p>@endforelse
@endsection
