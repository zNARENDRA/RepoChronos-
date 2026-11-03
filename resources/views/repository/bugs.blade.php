<div class="title-row"><h2>Bug tracker</h2><span class="counter">{{ $bugs->count() }}</span></div>
@if($write)
    <details class="disclosure" @if($errors->has('title') || $errors->has('details')) open @endif>
        <summary>Report a bug</summary>
        <form method="post" class="fields" action="{{ $base }}/bugs">@csrf
            <label>Title<input class="form-control" name="title" required maxlength="160" value="{{ old('title') }}"></label>
            <label>Details<textarea class="form-control" name="details" maxlength="4000">{{ old('details') }}</textarea></label>
            <button class="btn btn-success">Report as {{ auth()->user()->name }}</button>
        </form>
    </details>
@endif
@forelse($bugs as $bug)
    <article class="panel bug">
        <p class="eyebrow">Issue {{ $bug->id }} <span class="status">{{ $bug->status }}</span></p>
        <h2>{{ $bug->title }}</h2>
        @if($bug->details)<p>{{ $bug->details }}</p>@endif
        <p class="hint">Reported by {{ $bug->reporter_name ?? $bug->reporter }} · Assigned to {{ $bug->assignee ?? 'nobody yet' }}</p>
        @if($bug->fixed_at)
            <p>Resolved by {{ $bug->resolved_by ?? 'Legacy record' }} in {{ round($bug->resolution_seconds/3600,2) }} h · <abbr class="hash" title="{{ $bug->fix_sha }}">{{ substr($bug->fix_sha,0,8) }}</abbr></p>
        @elseif($write)
            <div class="bug-actions">
                <form method="post" action="{{ $base }}/bugs/{{ $bug->id }}">@csrf<input type="hidden" name="kind" value="assigned">
                    <label>Developer<select class="form-select" name="target">@foreach($people as $person)<option value="{{ $person->id }}">{{ $person->display_name }}</option>@endforeach</select></label><button class="btn btn-outline-success">Assign</button>
                </form>
                @if($bug->assignee)
                    <form method="post" action="{{ $base }}/bugs/{{ $bug->id }}">@csrf<input type="hidden" name="kind" value="progress"><button class="btn btn-outline-success">Mark in progress</button></form>
                    <form method="post" action="{{ $base }}/bugs/{{ $bug->id }}">@csrf<input type="hidden" name="kind" value="fixed">
                        <label>Fixing commit<select class="form-select" name="target">@foreach($commits as $commit)<option value="{{ $commit->id }}">{{ substr($commit->sha,0,8) }} · {{ $commit->message }}</option>@endforeach</select></label><button class="btn btn-success">Resolve with commit</button>
                    </form>
                @endif
            </div>
        @endif
        <details class="disclosure"><summary>Event history</summary>
            <p>Reported {{ $bug->reported_at }} by {{ $bug->reporter_name ?? $bug->reporter }}</p>
            @include('table',['rows'=>$events->where('bug_id',$bug->id)->values(),'columns'=>['at'=>'Time (UTC)','kind'=>'Event','actor'=>'Recorded by','display_name'=>'Assigned to','sha'=>'Fixing commit']])
        </details>
    </article>
@empty<p class="empty">No bugs reported for this repository.</p>@endforelse
