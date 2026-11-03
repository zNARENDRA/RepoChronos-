<section class="panel">
    <div class="title-row"><h2>Complexity analysis</h2>
        @if($write)<form method="post" action="{{ $base }}/analysis">@csrf<button class="btn btn-success" @disabled(!$pending)>Analyze next 15 revisions</button></form>@endif
    </div>
    <p class="hint">{{ $pending }} pending revisions · New measurements warn above {{ config('repochronos.threshold') }}.</p>
    @include('table',['rows'=>$measurements->items(),'columns'=>['function_name'=>'Function','path'=>'File','line'=>'Line','language'=>'Language','score'=>'Score','warning'=>'Risk','sha'=>'Commit','display_name'=>'Author']])
    {{ $measurements->links('pagination::bootstrap-5') }}
    <details class="disclosure"><summary>Revision coverage ({{ $runs->count() }})</summary>
        @include('table',['rows'=>$runs,'columns'=>['path'=>'File','sha'=>'Commit','status'=>'Status','reason'=>'Reason','language'=>'Language','threshold'=>'Threshold','engine'=>'Analyzer','analyzed_at'=>'Analyzed']])
    </details>
    <p class="hint">Warnings flag functions with more execution paths to maintain.</p>
</section>
