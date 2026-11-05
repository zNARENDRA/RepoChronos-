<div class="metrics">
    @foreach(['commits'=>'Commits','files'=>'Files','open'=>'Open bugs','fixed'=>'Resolved bugs','warnings'=>'Warnings'] as $key=>$label)
        <div class="metric metric-{{ $key }}" style="--order:{{ $loop->index }}"><span>{{ $label }}</span><strong>{{ $totals->$key }}</strong><span class="metric-decoration" aria-hidden="true">{{ ['commits'=>'↗','files'=>'▤','open'=>'◎','fixed'=>'✓','warnings'=>'!'][$key] }}</span></div>
    @endforeach
</div>
<div class="overview-grid">
    <section class="panel activity-panel">
        <h2>Commit activity</h2>
        <div class="chart" tabindex="0" role="region" aria-label="Commit activity chart">{!! App\Services\Chart::bars($activity,'commits','day') !!}</div>
        <details class="disclosure"><summary>Daily counts</summary>@include('table',['rows'=>$activity])</details>
    </section>
    <section class="panel contributors-panel">
        <h2>Contributors</h2>
        @forelse($people as $person)
            <a class="person" href="{{ $base }}/history?author={{ $person->id }}">
                <span class="avatar">{{ mb_strtoupper(mb_substr($person->display_name,0,1)) }}</span>
                <span>{{ $person->display_name }}</span><small>{{ $person->commits }} {{ Str::plural('commit',$person->commits) }}</small>
            </a>
        @empty<p class="empty">Contributors appear after import.</p>@endforelse
    </section>
<section class="panel trend-panel">
    <h2>Complexity trend</h2><p class="hint">Average score for functions in each commit’s changed files.</p>
    @if($trend->isNotEmpty())
        <div class="chart" tabindex="0" role="region" aria-label="Complexity trend chart">{!! App\Services\Chart::bars($trend,'mean_complexity','sha') !!}</div>
        <details class="disclosure"><summary>Revision measurements</summary>
            @include('table',['rows'=>$trend,'columns'=>['sha'=>'Commit','authored_at'=>'Authored','functions'=>'Functions','mean_complexity'=>'Mean','max_complexity'=>'Peak','warnings'=>'Warnings']])
        </details>
    @else<p class="empty">No measurements yet. <a href="{{ $base }}/analysis">Open complexity analysis →</a></p>@endif
</section>
</div>
@if($repo->role==='owner')
    <details class="disclosure" @if($errors->has('email') || $errors->has('role')) open @endif>
        <summary>Repository access</summary><p class="hint">Add a registered user. Editors can import, analyze and update bugs.</p>
        <form class="filter-form" method="post" action="{{ $base }}/members">@csrf
            <label>Email<input class="form-control" name="email" type="email" required></label>
            <label>Role<select class="form-select" name="role"><option value="viewer">Viewer</option><option value="editor">Editor</option></select></label>
            <button class="btn btn-success">Save membership</button>
        </form>
    </details>
@endif
