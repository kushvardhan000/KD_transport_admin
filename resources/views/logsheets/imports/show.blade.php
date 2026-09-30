@extends('layouts.app')

@section('title', 'Import Details · Transport')

@section('content')
@php
    $importInputClass = 'w-full rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100';
    // Primary export: carries the status value the server actually filtered the table with.
    $statusQuery = request()->only(['status']);
    if (($statusQuery['status'] ?? '') === '') {
        unset($statusQuery['status']);
    }
    $exportUrl = route('logsheets.imports.export', $import) . ($statusQuery !== [] ? '?' . http_build_query($statusQuery) : '');
    $exportStatusLabel = match ($scope) {
        'completed' => 'Completed only',
        'pending' => 'Pending only',
        default => 'Pending + Cleared (2 sheets)',
    };
    // Secondary manual override: ignores the status filter above.
    $overrideUrl = fn (string $force) => route('logsheets.imports.export', $import).'?'.http_build_query(['force_status' => $force]);
    $exportLinkClass = 'inline-flex min-h-[44px] items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-medium shadow-sm bg-brand-600 text-white hover:bg-brand-700';
    $overrideLinkClass = 'inline-flex min-h-[44px] items-center justify-center gap-1 rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700';
@endphp
<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-zinc-200 pb-5 dark:border-zinc-800">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-950 dark:text-zinc-50">Import Details</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">View consolidated log sheets and invalid rows for this import.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('logsheets.index') }}" class="inline-flex items-center gap-2 text-sm text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                ← Back to Imports
            </a>
            <form method="POST" action="{{ route('logsheets.imports.destroy', $import) }}" class="inline" onsubmit="return confirm('Delete this import and ALL associated data (log sheets, details, raw rows, clearings, and the uploaded file)? This cannot be undone.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Delete Import
                </button>
            </form>
        </div>
    </div>

    <!-- Header Card -->
    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-premium-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $import->original_filename }}</h3>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                    Period: {{ $import->date_from?->format('Y-m-d') }} to {{ $import->date_to?->format('Y-m-d') }}
                </p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Uploaded by: {{ $import->uploader?->name ?? '—' }} · {{ $import->created_at?->format('Y-m-d H:i') }}
                </p>
            </div>
            <div class="text-right sm:text-left">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Total Amount</p>
                <p class="mt-1 text-2xl font-semibold tracking-tight text-zinc-950 dark:text-zinc-50">₹{{ number_format($import->total_amount ?? 0, 2) }}</p>
            </div>
            <div class="text-right sm:text-left">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Booked</p>
                <p class="mt-1 text-2xl font-semibold tracking-tight text-zinc-950 dark:text-zinc-50">₹{{ number_format($import->total_booked_amount ?? 0, 2) }}</p>
            </div>
            <div class="text-right sm:text-left">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Diff</p>
                <p class="mt-1 text-2xl font-semibold tracking-tight {{ $import->total_diff > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">₹{{ number_format($import->total_diff ?? 0, 2) }}</p>
            </div>
            <div class="text-right sm:text-left">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Gross Wt</p>
                <p class="mt-1 text-2xl font-semibold tracking-tight text-zinc-950 dark:text-zinc-50">{{ number_format($import->total_gross_wt ?? 0, 3) }}</p>
            </div>
        </div>
        <div class="mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-800 grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Log Sheets</p>
                <p class="mt-1 text-xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $totalCount }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Cleared</p>
                <p class="mt-1 text-xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $clearedCount }} of {{ $totalCount }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Out-of-Range Rows</p>
                <p class="mt-1 text-xl font-semibold text-amber-600 dark:text-amber-400">{{ $import->out_of_range_rows ?? 0 }}</p>
            </div>
        </div>
    </div>

    <!-- Log Sheets Table -->
    <div class="rounded-xl border border-zinc-200 bg-white shadow-premium-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-3 border-b border-zinc-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between dark:border-zinc-800">
            <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Log Sheets in this Import ({{ $logsheets->total() }})</h3>
            <form method="GET" action="{{ route('logsheets.imports.show', $import) }}" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <label for="status" class="sr-only">Filter by status</label>
                <select id="status" name="status" x-on:change="$el.form.submit()" class="{{ $importInputClass }} sm:w-48">
                    <option value="" {{ $scope === 'all' ? 'selected' : '' }}>All Statuses</option>
                    <option value="completed" {{ $scope === 'completed' ? 'selected' : '' }}>Completed</option>
                    <option value="pending" {{ $scope === 'pending' ? 'selected' : '' }}>Pending</option>
                </select>
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-lg bg-brand-600 px-4 text-sm font-medium text-white shadow-sm hover:bg-brand-700">Apply Filter</button>
                @if($scope !== 'all')
                    <a href="{{ route('logsheets.imports.show', $import) }}" class="inline-flex min-h-[44px] items-center justify-center rounded-lg border border-zinc-300 px-4 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">Clear</a>
                @endif
            </form>
        </div>
        @if(session('error'))
            <div class="mx-6 mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                {{ session('error') }}
            </div>
        @endif
        @if($errors->any())
            <div class="mx-6 mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                <ul class="list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if($logsheets->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full min-w-max text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50/70 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50 dark:text-zinc-400">
                            <th class="px-6 py-3 text-left whitespace-nowrap">Log Sheet No</th>
                            <th class="px-6 py-3 text-right whitespace-nowrap">Amount</th>
                            <th class="px-6 py-3 text-right whitespace-nowrap">Booked</th>
                            <th class="px-6 py-3 text-right whitespace-nowrap">Diff</th>
                            <th class="px-6 py-3 text-right whitespace-nowrap">Gross Wt</th>
                            <th class="px-6 py-3 text-center whitespace-nowrap">Consignments</th>
                            <th class="px-6 py-3 text-center whitespace-nowrap">Status</th>
                            <th class="px-6 py-3 text-center whitespace-nowrap">Out of Range</th>
                            <th class="px-6 py-3 text-right whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @foreach($logsheets as $logsheet)
                            <tr class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-800/30 @if($logsheet->fully_out_of_requested_range) bg-amber-50 dark:bg-amber-900/20 @endif">
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('logsheets.show', $logsheet) }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-300">
                                        {{ $logsheet->log_sheet_no }}
                                        @if($logsheet->fully_out_of_requested_range)
                                            <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                                                <span class="h-1.5 w-1.5 rounded-full bg-amber-600"></span>
                                                Fully Out of Range
                                            </span>
                                        @endif
                                    </a>
                                </td>
                                <td class="px-6 py-3 text-right font-mono whitespace-nowrap">{{ number_format($logsheet->total_actual_amount ?? 0, 2) }}</td>
                                <td class="px-6 py-3 text-right font-mono whitespace-nowrap">{{ number_format($logsheet->total_booked_amount ?? 0, 2) }}</td>
                                <td class="px-6 py-3 text-right font-mono {{ $logsheet->total_diff > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }} whitespace-nowrap">{{ number_format($logsheet->total_diff ?? 0, 2) }}</td>
                                <td class="px-6 py-3 text-right font-mono whitespace-nowrap">{{ number_format($logsheet->total_gross_wt ?? 0, 3) }}</td>
                                <td class="px-6 py-3 text-center font-mono whitespace-nowrap">{{ $logsheet->consignment_count }}</td>
                                <td class="px-6 py-3 text-center whitespace-nowrap">
                                    @if($logsheet->status === 'cleared')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
                                            Cleared
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-600"></span>
                                            Pending
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-center whitespace-nowrap">
                                    @if($logsheet->fully_out_of_requested_range)
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-amber-600 dark:text-amber-400">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                            </svg>
                                            Yes
                                        </span>
                                    @else
                                        <span class="text-xs text-emerald-600 dark:text-emerald-400">
                                            <svg class="h-3 w-3 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            No
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center justify-end gap-2 text-xs">
                                        <a href="{{ route('logsheets.show', $logsheet) }}" class="text-brand-600 hover:underline dark:text-brand-500">View</a>
                                        <form method="POST" action="{{ route('logsheets.destroy', $logsheet) }}" class="inline" onsubmit="return confirm('Delete this logsheet and all related records?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-200 min-h-[44px] min-w-[44px] flex items-center justify-center px-3">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-col gap-2 border-t border-zinc-200 px-6 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Showing {{ $logsheets->firstItem() ?? 0 }}-{{ $logsheets->lastItem() ?? 0 }} of {{ $logsheets->total() }}
                </p>
                {{ $logsheets->links() }}
            </div>
        @else
            <div class="px-6 py-16 text-center">
                <svg class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                    @if($scope === 'all')
                        No log sheets in this import.
                    @else
                        No {{ $scope === 'completed' ? 'completed' : 'pending' }} log sheets in this import.
                    @endif
                </p>
            </div>
        @endif
    </div>

    <!-- Export Excel -->
    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-premium-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Export Excel</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Exports exactly what is on screen &mdash;
                    <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $exportStatusLabel }}</span>
                    @if($logsheets->total() > 0)
                        &middot; {{ $logsheets->total() }} matching {{ \Illuminate\Support\Str::plural('log sheet', $logsheets->total()) }}
                    @endif
                    .
                </p>
            </div>
            <a href="{{ $exportUrl }}" class="{{ $exportLinkClass }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/>
                </svg>
                Export current view
            </a>
        </div>
        <div class="mt-4 flex flex-col gap-2 border-t border-zinc-200 pt-3 sm:flex-row sm:items-center sm:gap-3 dark:border-zinc-800">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                Override filter (ignores the Status filter above):
            </span>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ $overrideUrl('all') }}" class="{{ $overrideLinkClass }}">All (2 sheets)</a>
                <a href="{{ $overrideUrl('completed') }}" class="{{ $overrideLinkClass }}">Completed only</a>
                <a href="{{ $overrideUrl('pending') }}" class="{{ $overrideLinkClass }}">Pending only</a>
            </div>
        </div>
    </div>

    <!-- Invalid Rows Section -->
    <div class="rounded-xl border border-zinc-200 bg-white shadow-premium-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Invalid Rows ({{ $invalidRows->total() }})</h3>
        </div>
        @if($invalidRows->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full min-w-max text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50/70 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50 dark:text-zinc-400">
                            <th class="px-6 py-3 text-left whitespace-nowrap">Row #</th>
                            <th class="px-6 py-3 text-left whitespace-nowrap">Log Sheet No</th>
                            <th class="px-6 py-3 text-left whitespace-nowrap">Error</th>
                            <th class="px-6 py-3 text-left whitespace-nowrap">Raw Data</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @foreach($invalidRows as $row)
                            <tr class="bg-red-50 dark:bg-red-900/20">
                                <td class="px-6 py-3 whitespace-nowrap">{{ $row->row_number_in_file }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">{{ $row->log_sheet_no ?? '—' }}</td>
                                <td class="px-6 py-3 text-red-600 dark:text-red-400 whitespace-nowrap">{{ $row->validation_error }}</td>
                                <td class="px-6 py-3 max-w-xs">
                                    <pre class="text-xs text-zinc-600 dark:text-zinc-400 whitespace-pre-wrap overflow-auto max-h-24">{{ json_encode($row->raw_data, JSON_PRETTY_PRINT) }}</pre>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-col gap-2 border-t border-zinc-200 px-6 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Showing {{ $invalidRows->firstItem() ?? 0 }}-{{ $invalidRows->lastItem() ?? 0 }} of {{ $invalidRows->total() }}
                </p>
                {{ $invalidRows->links() }}
            </div>
        @else
            <div class="px-6 py-16 text-center">
                <svg class="mx-auto h-12 w-12 text-emerald-300 dark:text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">No invalid rows in this import.</p>
            </div>
        @endif
    </div>
</div>
@endsection