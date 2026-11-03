<section class="panel">
    <h2>Files changed by multiple developers</h2>
    <p class="hint">Repository parameter <code>{{ $repo->id }}</code></p>
    <pre><code>{{ $sql }}</code></pre>
    <h3>Query results</h3>
    @include('table',['rows'=>$result])
    <p class="hint">More queries are in <code>db/examiner.sql</code>.</p>
</section>
