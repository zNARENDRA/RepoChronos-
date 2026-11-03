@php
    $records=collect($rows);
    $headings=$columns ?? ($records->isEmpty() ? [] : collect(array_keys((array)$records->first()))->mapWithKeys(fn($key)=>[$key => str_replace('_',' ',$key)])->all());
@endphp
@if($records->isEmpty())
    <p class="empty">No records for this selection.</p>
@else
    <div class="table-responsive" tabindex="0" aria-label="Scrollable data table">
        <table class="table align-middle">
            <thead><tr>@foreach($headings as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($records as $row)
                <tr style="--order:{{ min($loop->index,7) }}">
                    @foreach($headings as $key=>$label)
                        @php $value=((array)$row)[$key] ?? null; @endphp
                        <td>
                            @if(in_array($key,['sha','fix_sha']) && $value)<abbr class="hash" title="{{ $value }}">{{ substr($value,0,8) }}</abbr>
                            @elseif($key==='warning')<span class="{{ $value?'warning-tag':'' }}">{{ $value?'Warning':'—' }}</span>
                            @elseif(is_bool($value)){{ $value?'Yes':'No' }}
                            @else{{ $value ?? '—' }}@endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
