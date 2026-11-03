@extends('layout')
@section('content')
<a href="/">← Repositories</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mt-3">
<div><p class="eyebrow">REPOSITORY / {{ $repo->role }}</p><h1>{{ $repo->full_name }}</h1></div>
@if($repo->role!=='viewer' && $repo->source_key!=='sample')
<div class="d-flex gap-2"><form method="post" action="/r/{{ $repo->id }}/import">@csrf<button class="btn btn-success">Sync latest</button></form>
@if($checkpoint && $checkpoint->more)<form method="post" action="/r/{{ $repo->id }}/import">@csrf<input type="hidden" name="head" value="{{ $checkpoint->head_sha }}"><button class="btn btn-outline-success">Continue import</button></form>@endif</div>@endif
</div>
<nav class="nav nav-pills my-4 gap-2">
@foreach(['overview'=>'Overview','history'=>'Change history','analysis'=>'Complexity','compare'=>'Compare revisions','bugs'=>'Bug tracker','sql'=>'SQL workbench'] as $key=>$label)
<a class="nav-link {{ $tab===$key?'active':'' }}" href="/r/{{ $repo->id }}/{{ $key }}">{{ $label }}</a>
@endforeach</nav>

@if($tab==='overview')
<div class="row g-3 mb-4">
@foreach((array)$totals as $label=>$number)<div class="col"><section class="card p-3"><span>{{ ucfirst($label) }}</span><strong class="fs-2">{{ $number }}</strong></section></div>@endforeach
</div>
<div class="row g-4"><section class="col-lg-7"><div class="card p-4"><h2>Complexity by changed revision</h2><p class="small">Mean of all functions measured in each commit's changed files. Unanalyzed files are excluded.</p>
<div class="chart">{!! App\Services\Chart::bars($trend,'mean_complexity','sha') !!}</div>
@include('table',['rows'=>$trend])
</div></section><section class="col-lg-5"><div class="card p-4 mb-4"><h2>Developer contributions</h2>@include('table',['rows'=>$people])</div><div class="card p-4"><h2>Commit activity</h2>{!! App\Services\Chart::bars($activity,'commits','day') !!}@include('table',['rows'=>$activity])</div></section></div>
@if($repo->role==='owner')
<section class="card p-4 my-4"><h2>Repository access</h2><p>Add a registered user's email. Viewers can read; editors can import, analyze and track bugs.</p><form class="row g-3" method="post" action="/r/{{ $repo->id }}/members">@csrf
<label class="col">Email<input class="form-control" name="email" type="email" required></label><label class="col">Role<select class="form-select" name="role"><option value="viewer">Viewer</option><option value="editor">Editor</option></select></label><div class="col align-self-end"><button class="btn btn-success">Save membership</button></div></form></section>
@endif
@elseif($tab==='history')
<form class="row g-3 mb-4">
<label class="col">File path<input class="form-control" name="path" value="{{ request('path') }}"></label>
<label class="col">Developer<select class="form-select" name="author"><option value="">All developers</option>@foreach($people as $person)<option value="{{ $person->id }}" @selected(request('author')==$person->id)>{{ $person->display_name }}</option>@endforeach</select></label>
<div class="col align-self-end"><button class="btn btn-success">Filter</button></div></form>
<section class="card p-4"><h2>Who changed what and when</h2>@include('table',['rows'=>$history->items()]){{ $history->links() }}</section>
@elseif($tab==='analysis')
<section class="card p-4 mb-4"><h2>Function complexity</h2><p>Lizard counts decisions in supported source languages. All measured functions are retained, including those below the warning threshold. Unsupported revisions are listed separately.</p><p>{{ $pending }} pending revisions. New runs warn above {{ config('repochronos.threshold') }}. Each run stores its threshold.</p>
@if($repo->role!=='viewer')<form method="post" action="/r/{{ $repo->id }}/analysis">@csrf<button class="btn btn-success">Analyze next 15 revisions</button></form>@endif
</section>
<section class="card p-4 mb-4"><h2>All measurements</h2><p>Warning values indicate maintainability risk and do not establish that a function contains bugs.</p>
@include('table',['rows'=>$measurements->items()]){{ $measurements->links() }}</section>
<section class="card p-4"><h2>Revision coverage and unsupported files</h2>@include('table',['rows'=>$runs])</section>
@elseif($tab==='compare')
<section class="card p-4 mb-4"><h2>Compare file revisions</h2><form class="row g-3">
<label class="col">File<select class="form-select" name="file">@foreach($files as $file)<option value="{{ $file->id }}" @selected(request('file')==$file->id)>{{ $file->path }}</option>@endforeach</select></label>
@foreach(['before'=>'Before','after'=>'After'] as $key=>$label)<label class="col">{{ $label }} commit<select class="form-select" name="{{ $key }}">@foreach($commits as $commit)<option value="{{ $commit->id }}" @selected(request($key)==$commit->id)>{{ substr($commit->sha,0,8) }} · {{ $commit->message }}</option>@endforeach</select></label>@endforeach
<div class="col-auto align-self-end"><button class="btn btn-success">Compare</button></div></form></section>
@if(isset($before))<div class="row g-4">@foreach(['before','after'] as $side)<section class="col-lg-6"><div class="card p-4"><h2>{{ ucfirst($side) }}</h2>@include('table',['rows'=>$$side])</div></section>@endforeach</div>
<section class="card p-4 mt-4"><h2>Function score changes</h2>@include('table',['rows'=>$deltas])</section><section class="card p-4 mt-4"><h2>File complexity trend</h2>{!! App\Services\Chart::bars($fileTrend,'mean_complexity','sha') !!}@include('table',['rows'=>$fileTrend])</section>@endif
<p class="small mt-3">Compare matching function names/signatures in the two revisions. Renamed functions may need manual matching. An empty result can mean that revision was not analyzed or did not change the selected file.</p>
@elseif($tab==='bugs')
@if($repo->role!=='viewer')<section class="card p-4 mb-4"><h2>Report a bug</h2><form method="post" action="/r/{{ $repo->id }}/bugs">@csrf
<label class="form-label d-block">Title<input class="form-control" name="title" required maxlength="160"></label><label class="form-label d-block">Details<textarea class="form-control" name="details" maxlength="4000"></textarea></label><button class="btn btn-success">Report as {{ auth()->user()->name }}</button></form></section>@endif
@foreach($bugs as $bug)<section class="card p-4 mb-4">
<h2>#{{ $bug->id }} · {{ $bug->title }}</h2><p>{{ $bug->details }}</p>
<p><strong>{{ $bug->status }}</strong> · Reporter {{ $bug->reporter_name ?? $bug->reporter }} · Assignee {{ $bug->assignee ?? 'Unassigned' }}</p>
@if($bug->fixed_at)<p>Resolved by {{ $bug->resolved_by ?? 'Legacy record' }} · Fix {{ $bug->fix_sha }} · {{ round($bug->resolution_seconds/3600,2) }} hours from report to fix.</p>
@elseif($repo->role!=='viewer')
<div class="row g-3">
<form class="col" method="post" action="/r/{{ $repo->id }}/bugs/{{ $bug->id }}">@csrf<input type="hidden" name="kind" value="assigned"><label class="d-block">Assign developer<select class="form-select mb-2" name="target">@foreach($people as $person)<option value="{{ $person->id }}">{{ $person->display_name }}</option>@endforeach</select></label><button class="btn btn-outline-success">Assign</button></form>
@if($bug->assignee)
<form class="col-auto align-self-end" method="post" action="/r/{{ $repo->id }}/bugs/{{ $bug->id }}">@csrf<input type="hidden" name="kind" value="progress"><button class="btn btn-outline-success">Mark in progress</button></form>
<form class="col" method="post" action="/r/{{ $repo->id }}/bugs/{{ $bug->id }}">@csrf<input type="hidden" name="kind" value="fixed"><label class="d-block">Fixing commit<select class="form-select mb-2" name="target">@foreach($commits as $commit)<option value="{{ $commit->id }}">{{ substr($commit->sha,0,8) }} · {{ $commit->message }}</option>@endforeach</select></label><button class="btn btn-success">Resolve with commit</button></form>
@endif</div>@endif
<details class="mt-3"><summary>Complete event history</summary><p>Reported {{ $bug->reported_at }} by {{ $bug->reporter_name ?? $bug->reporter }}</p>@include('table',['rows'=>$events->where('bug_id',$bug->id)->values()])</details>
</section>@endforeach
@elseif($tab==='sql')
<section class="card p-4"><h2>Files changed by multiple developers</h2><pre>{{ $sql }}</pre><p>Bound repository parameter {{ $repo->id }}</p>
@include('table',['rows'=>$result])
<p>More reports are in db/examiner.sql for execution in the Supabase SQL editor.</p></section>
@endif
@endsection
