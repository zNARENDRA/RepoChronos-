@extends('layout')
@section('title','Repositories')
@section('content')
<div class="page-intro">
    <p class="eyebrow">Workspace</p><h1>Repositories<em>.</em></h1>
    <p class="intro">Follow the code. Understand the changes.</p>
    @if(auth()->user()->github_id)
        <p class="minor-link">GitHub sign-in linked</p>
    @else
        <form method="post" action="/auth/github/link">@csrf<button class="btn btn-outline-success">Link GitHub for sign-in</button></form>
    @endif
</div>
<div class="directory-grid">
<section aria-label="Repository directory">
    <div class="directory-heading"><h2>Your repositories</h2><span class="counter">{{ $repos->count() }}</span></div>
    @forelse($repos as $repo)
        <a class="directory-row" style="--order:{{ min($loop->index,7) }}" href="/r/{{ $repo->id }}">
            <span class="repo-symbol" aria-hidden="true">⑂</span>
            <span class="directory-info"><strong>{{ $repo->full_name }}</strong><small>{{ str_starts_with($repo->source_key,'github:')?'GitHub':'Test fixture' }} · {{ ucfirst($repo->role) }}</small></span>
            <span class="open-arrow" aria-hidden="true">↗</span>
        </a>
    @empty
        <p class="empty">No GitHub repositories connected yet. Connect your repository below to import its real commits, contributors and changed files, or ask its owner to add your registered email.</p>
    @endforelse
</section>
<details class="disclosure connect-box" open>
    <summary>Connect a repository</summary>
    <form class="connect-form" method="post" action="/repositories">@csrf
        <label>GitHub URL or owner/name<input class="form-control" name="repository" value="{{ old('repository') }}" placeholder="octocat/Hello-World" required></label>
        <button class="btn btn-success">Connect <span aria-hidden="true">↗</span></button>
    </form>
</details>
</div>
@endsection
