@extends('layout')
@php
    $tabs=['overview'=>'Overview','history'=>'Change history','analysis'=>'Complexity','compare'=>'Compare revisions','bugs'=>'Bug tracker','sql'=>'SQL workbench'];
    $write=$repo->role!=='viewer';
    $base='/r/'.$repo->id;
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
<nav class="section-nav" aria-label="Repository sections">
    @foreach($tabs as $key=>$label)
        <a href="{{ $base }}/{{ $key }}" @if($tab===$key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
<div class="tab-body">
    @include('repository.'.$tab)
</div>
@endsection
