@php $records=collect($rows); @endphp
@if($records->isEmpty())<p class="text-secondary">No records for this selection.</p>
@else<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr>
@foreach(array_keys((array)$records->first()) as $key)<th>{{ str_replace('_',' ',$key) }}</th>@endforeach
</tr></thead><tbody>
@foreach($records as $row)<tr>@foreach((array)$row as $value)<td>{{ is_bool($value)?($value?'Yes':'No'):($value ?? '—') }}</td>@endforeach</tr>@endforeach
</tbody></table></div>@endif
