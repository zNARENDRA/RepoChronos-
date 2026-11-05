@extends('layout')
@php
    $tabs=['overview'=>'Overview','history'=>'Change history','analysis'=>'Complexity','compare'=>'Compare revisions','bugs'=>'Bug tracker','sql'=>'SQL workbench'];
    $write=$repo->role!=='viewer';
    $base='/r/'.$repo->id;
    $icons=['overview'=>'▦','history'=>'↳','analysis'=>'⌁','compare'=>'⇄','bugs'=>'◎','sql'=>'≡'];
@endphp
@section('title', $tabs[$tab].' · '.$repo->full_name)
@section('content')
<div class="page-intro">
    <div class="crumb"><a href="/">Repositories</a><span>/</span>{{ ucfirst($repo->role) }}</div>
    <div class="title-row">
        <h1>{{ $repo->full_name }}<em>.</em></h1>
        @if($write && $repo->source_key!=='sample')
            <div class="actions">
                <form method="post" action="{{ $base }}/import">@csrf<button class="btn btn-success">Sync latest</button></form>
                @if($checkpoint && $checkpoint->more)
                    <form method="post" action="{{ $base }}/import">@csrf<input type="hidden" name="head" value="{{ $checkpoint->head_sha }}"><button class="btn btn-outline-success">Continue import</button></form>
                @endif
            </div>
        @endif
    </div>
</div>
<div class="repository-workspace">
<aside class="workspace-sidebar">
<p class="eyebrow">Repository</p>
<nav class="section-nav" aria-label="Repository sections" data-repository="{{ $repo->id }}">
    @foreach($tabs as $key=>$label)
        <a href="{{ $base }}/{{ $key }}" aria-label="{{ $label }}" @if($tab===$key) aria-current="page" @endif><span class="nav-symbol" aria-hidden="true">{{ $icons[$key] }}</span><span>{{ $label }}</span><span class="nav-arrow" aria-hidden="true">↗</span></a>
    @endforeach
</nav>
<div class="sidebar-note"><span class="orbit-mark" aria-hidden="true"><i></i><i></i><i></i></span><strong>Every change.<br>Every chapter.</strong><p>Your repository’s history, connected.</p></div>
</aside>
<div class="workspace-content">
<p class="navigation-status visually-hidden" role="status" aria-live="polite"></p>
<div class="tab-body" id="repository-section" tabindex="-1" aria-label="{{ $tabs[$tab] }}">
    @include('repository.'.$tab)
</div>
</div>
</div>
@endsection
