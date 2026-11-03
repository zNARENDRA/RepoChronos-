@extends('layout')
@section('title','Repositories')
@section('content')
<div class="page-intro">
    <p class="eyebrow">Workspace</p><h1>Repositories<em>.</em></h1>
</div>
<section aria-label="Repository directory">
    <div class="directory-heading"><h2>Your repositories</h2><span class="counter">{{ $repos->count() }}</span></div>
    @forelse($repos as $repo)
        <a class="directory-row" style="--order:{{ min($loop->index,7) }}" href="/r/{{ $repo->id }}">
            <span class="repo-symbol" aria-hidden="true">⑂</span>
            <span class="directory-info"><strong>{{ $repo->full_name }}</strong><small>{{ $repo->source_key==='sample'?'Offline demo':'GitHub' }} · {{ ucfirst($repo->role) }}</small></span>
            <span class="open-arrow" aria-hidden="true">↗</span>
        </a>
    @empty
        <p class="empty">Connect a repository below, or ask an owner to add your registered email.</p>
    @endforelse
</section>
<details class="disclosure connect-box" @if($repos->isEmpty() || $errors->has('repository')) open @endif>
    <summary>Connect a repository</summary>
    <form class="connect-form" method="post" action="/repositories">@csrf
        <label>GitHub URL or owner/name<input class="form-control" name="repository" value="{{ old('repository') }}" placeholder="octocat/Hello-World" required></label>
        <button class="btn btn-success">Connect <span aria-hidden="true">↗</span></button>
    </form>
</details>
@endsection
