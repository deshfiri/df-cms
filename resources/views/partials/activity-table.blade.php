{{--
    A table of historical activity counts. Expects: $rowHeading,
    $columns ([key, label, help]), $rows ([label, values, strong?]). A value
    of '—' marks a column that does not apply to that row.
--}}
<div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
        <thead>
            <tr>
                <th>{{ $rowHeading }}</th>
                @foreach ($columns as [$key, $label, $help])
                    <th class="text-end" title="{{ $help }}">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="{{ ($row['strong'] ?? false) ? 'fw-semibold' : '' }}">
                    <td>{{ $row['label'] }}</td>
                    @foreach ($columns as [$key])
                        <td class="text-end">{{ $row['values'][$key] ?? 0 }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
