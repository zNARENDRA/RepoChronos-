<section class="panel">
    <h2>Change history</h2>
    <form class="filter-form">
        <label>File path<input class="form-control" name="path" placeholder="Search paths" value="{{ request('path') }}"></label>
        <label>Developer<select class="form-select" name="author"><option value="">All developers</option>
            @foreach($people as $person)<option value="{{ $person->id }}" @selected(request('author')==$person->id)>{{ $person->display_name }}</option>@endforeach
        </select></label><button class="btn btn-success">Filter</button>
    </form>
    @include('table',['rows'=>$history->items(),'columns'=>['path'=>'File','previous_path'=>'Previous path','kind'=>'Change','display_name'=>'Author','sha'=>'Commit','message'=>'Message','authored_at'=>'Authored (UTC)','additions'=>'+ Lines','deletions'=>'− Lines']])
    {{ $history->links('pagination::bootstrap-5') }}
</section>
