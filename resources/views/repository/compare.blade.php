<section class="panel">
    <h2>Compare revisions</h2>
    <form class="filter-form">
        <label>File<select class="form-select" name="file">
            @foreach($files as $file)<option value="{{ $file->id }}" @selected(request('file')==$file->id)>{{ $file->path }}</option>@endforeach
        </select></label>
        @foreach(['before'=>'Before','after'=>'After'] as $key=>$label)
            <label>{{ $label }} commit<select class="form-select" name="{{ $key }}">
                @foreach($commits as $commit)<option value="{{ $commit->id }}" @selected(request($key)==$commit->id)>{{ substr($commit->sha,0,8) }} · {{ $commit->message }}</option>@endforeach
            </select></label>
        @endforeach
        <button class="btn btn-success">Compare</button>
    </form>
    @if(isset($before))
        <h3>Function score changes</h3>
        @include('table',['rows'=>$deltas])
        @foreach(['before','after'] as $side)
            <details class="disclosure"><summary>{{ ucfirst($side) }} revision</summary>
                @include('table',['rows'=>$$side,'columns'=>['sha'=>'Commit','analysis_status'=>'Status','signature'=>'Signature','line'=>'Line','score'=>'Score','display_name'=>'Author']])
            </details>
        @endforeach
        <h3 class="mt-4">File complexity trend</h3><div class="chart">{!! App\Services\Chart::bars($fileTrend,'mean_complexity','sha') !!}</div>
        <details class="disclosure"><summary>Trend values</summary>@include('table',['rows'=>$fileTrend])</details>
    @endif
    <p class="hint">Choose two analyzed commits that changed the selected file. Functions are matched by signature.</p>
</section>
