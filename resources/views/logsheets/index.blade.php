@extends('layouts.app')

@section('title', 'Logsheet Imports · Transport')

@section('content')
@php
    $filterInputClass = 'w-full rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100';
@endphp

<x-layout.breadcrumb :items="['Dashboard' => route('dashboard'), 'Logsheet Imports' => route('logsheets.index')]" />

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-zinc-200 pb-5 dark:border-zinc-800">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-950 dark:text-zinc-50">Logsheet Imports</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Upload and consolidate transport logsheet Excel files.</p>
        </div>
        <a href="{{ route('logsheets.records') }}" class="inline-flex items-center gap-2 text-sm text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
            View all log sheets
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    </div>

    <!-- Upload Card -->
    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-premium-sm dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="mb-4 text-base font-semibold text-zinc-900 dark:text-zinc-100">Upload Excel File</h2>
        <form method="POST" action="{{ route('logsheets.store') }}" enctype="multipart/form-data" class="space-y-4" x-data="logsheetUpload()" x-ref="form" @submit="submit">
            @csrf

            @if(session('success') || session('warning') || session('info') || session('error'))
                <div class="space-y-2">
                    @if(session('success'))
                        <div class="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-400">
                            <span>{{ session('success') }}</span>
                            <button type="button" class="text-emerald-500 hover:text-emerald-700" onclick="this.parentElement.remove()">&times;</button>
                        </div>
                    @endif
                    @if(session('warning'))
                        <div class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-400">
                            <span>{{ session('warning') }}</span>
                            <button type="button" class="text-amber-500 hover:text-amber-700" onclick="this.parentElement.remove()">&times;</button>
                        </div>
                    @endif
                    @if(session('info'))
                        <div class="flex items-center justify-between rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-400">
                            <span>{{ session('info') }}</span>
                            <button type="button" class="text-blue-500 hover:text-blue-700" onclick="this.parentElement.remove()">&times;</button>
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="flex items-center justify-between rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                            <span>{{ session('error') }}</span>
                            <button type="button" class="text-red-500 hover:text-red-700" onclick="this.parentElement.remove()">&times;</button>
                        </div>
                    @endif
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-3">
                <!-- Date From -->
                <div>
                    <label for="date_from" class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">From Date</label>
                    <div class="relative">
                        <input 
                            type="date" 
                            id="date_from" 
                            name="date_from" 
                            x-model="dateFrom"
                            class="{{ $filterInputClass }} @error('date_from') border-red-500 @enderror"
                        >
                        @error('date_from')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Date To -->
                <div>
                    <label for="date_to" class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">To Date</label>
                    <div class="relative">
                        <input 
                            type="date" 
                            id="date_to" 
                            name="date_to" 
                            x-model="dateTo"
                            class="{{ $filterInputClass }} @error('date_to') border-red-500 @enderror"
                        >
                        @error('date_to')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Presets -->
                <div class="flex items-end">
                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Quick select</label>
                    <div class="flex flex-wrap gap-2 w-full">
                        <button type="button" @click="setPreset(presets.today)" class="inline-flex h-9 items-center justify-center rounded-lg border border-zinc-300 px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">Today</button>
                        <button type="button" @click="setPreset(presets.thisMonth)" class="inline-flex h-9 items-center justify-center rounded-lg border border-zinc-300 px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">This month</button>
                        <button type="button" @click="setPreset(presets.lastMonth)" class="inline-flex h-9 items-center justify-center rounded-lg border border-zinc-300 px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">Last month</button>
                    </div>
                </div>
            </div>

            <!-- File Drop Zone -->
            <div class="relative">
                <label for="file" class="block cursor-pointer">
                    <div 
                        class="flex flex-col items-center justify-center gap-4 rounded-lg border-2 border-dashed border-zinc-300 px-6 py-6 dark:border-zinc-700"
                        :class="{ 'border-brand-500 bg-brand-50 dark:bg-brand-900/20': fileName, 'hover:border-brand-500': !fileName }"
                        @dragover.prevent
                        @drop.prevent="onFileSelect($event)"
                    >
                        <div class="flex flex-col items-center gap-2" x-show="!fileName">
                            <svg class="h-10 w-10 text-zinc-400 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                            </svg>
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">Drag & drop or click to select</span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">.xlsx, .xls, .csv</span>
                        </div>
                        <div class="flex items-center justify-between w-full max-w-md p-3 bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700" x-show="fileName" role="status">
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                <svg class="h-6 w-6 text-brand-600 dark:text-brand-400 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 17H7V7h5v5h5v5z"/>
                                </svg>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate" x-text="fileName"></p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400" x-text="fileSize + ' KB'"></p>
                                </div>
                            </div>
                            <button type="button" @click="clearFile()" class="flex-shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" aria-label="Remove file">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <input 
                        type="file" 
                        id="file" 
                        name="file" 
                        accept=".xlsx,.xls,.csv" 
                        class="sr-only" 
                        x-ref="fileInput"
                        @change="onFileSelect($event)"
                        required
                    >
                    @error('file')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </label>
            </div>

            <p class="text-xs text-zinc-500">Accepted formats: .xlsx, .xls, .csv. Total Amount is the sum of Actual Amount.</p>

            <div class="pt-2">
                <button 
                    type="submit" 
                    :disabled="submitting || !fileName"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-6 text-sm font-medium text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <svg x-show="submitting" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span x-text="submitting ? 'Uploading...' : 'Upload & Import'"></span>
                </button>
            </div>
        </form>
    </div>

    @include('logsheets.partials.clear-payments')

    <!-- Period Filter + Imports Table -->
    <div class="rounded-xl border border-zinc-200 bg-white shadow-premium-sm dark:border-zinc-800 dark:bg-zinc-900">
        <form method="GET" action="{{ route('logsheets.index') }}" class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-800">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div>
                        <label for="period_from" class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Period from</label>
                        <input type="date" id="period_from" name="period_from" value="{{ request('period_from') }}" class="{{ $filterInputClass }}">
                    </div>
                    <div>
                        <label for="period_to" class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Period to</label>
                        <input type="date" id="period_to" name="period_to" value="{{ request('period_to') }}" class="{{ $filterInputClass }}">
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="inline-flex h-9 items-center justify-center rounded-lg bg-brand-600 px-4 text-sm font-medium text-white shadow-sm hover:bg-brand-700">Filter</button>
                    @if(request()->hasAny(['period_from', 'period_to']))
                        <a href="{{ route('logsheets.index') }}" class="inline-flex h-9 items-center justify-center rounded-lg border border-zinc-300 px-4 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">Clear</a>
                    @endif
                </div>
            </div>
        </form>

        <!-- Table (desktop) -->
        <div class="hidden sm:block overflow-x-auto">
            <table class="w-full min-w-max text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 bg-zinc-50/70 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50 dark:text-zinc-400">
                        <th class="px-6 py-3 text-left whitespace-nowrap">Period</th>
                        <th class="px-6 py-3 text-right whitespace-nowrap">Total Amount</th>
                        <th class="px-6 py-3 text-center whitespace-nowrap">Status</th>
                        <th class="px-6 py-3 text-center whitespace-nowrap">Invalid Rows</th>
                        <th class="px-6 py-3 text-right whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($imports as $import)
                        <tr class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-800/30">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div>
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $import->date_from?->format('j M Y') ?? '—' }} &rarr; {{ $import->date_to?->format('j M Y') ?? '—' }}
                                    </span>
                                    <br>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $import->original_filename ?? '—' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right font-mono whitespace-nowrap">
                                <span class="{{ ($import->total_amount ?? 0) > 0 ? 'text-red-600 dark:text-red-400' : '' }}">&#8377;{{ number_format($import->total_amount ?? 0, 2) }}</span>
                            </td>
                            <td class="px-6 py-4 text-center whitespace-nowrap">
                                @php
                                    // Both counts come from sub-selects and are
                                    // therefore never null, but a 0/0 import must
                                    // still not divide or compare against null.
                                    $cleared = (int) ($import->cleared_count ?? 0);
                                    $total = (int) ($import->log_sheet_numbers_count ?? 0);
                                    if ($total === 0 || $cleared === 0) {
                                        $badge = '<span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800 dark:bg-amber-900/30 dark:text-amber-400"><span class="h-1.5 w-1.5 rounded-full bg-amber-600"></span>Pending</span>';
                                    } elseif ($cleared === $total) {
                                        $badge = '<span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400"><span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span>Cleared</span>';
                                    } else {
                                        $badge = '<span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 text-xs font-bold text-blue-800 dark:bg-blue-900/30 dark:text-blue-400"><span class="h-1.5 w-1.5 rounded-full bg-blue-600"></span>Partially cleared ('.$cleared.'/'.$total.')</span>';
                                    }
                                @endphp
                                {!! $badge !!}
                            </td>
                            <td class="px-6 py-4 text-center whitespace-nowrap">
                                @php
                                    $invalidCount = $import->invalid_count ?? 0;
                                @endphp
                                @if($invalidCount > 0)
                                    <a href="{{ route('logsheets.imports.show', ['import' => $import->id]) }}#invalid-rows" class="inline-flex items-center gap-1 text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                        </svg>
                                        <span class="font-semibold">{{ $invalidCount }}</span>
                                    </a>
                                @else
                                    <span class="inline-flex items-center gap-1 text-sm text-zinc-500 dark:text-zinc-400">
                                        <svg class="h-4 w-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        <span>{{ $invalidCount }}</span>
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <div class="inline-flex items-center justify-end gap-2 text-xs">
                                    <a href="{{ route('logsheets.imports.show', ['import' => $import->id]) }}" class="text-brand-600 hover:underline dark:text-brand-500 min-h-[44px] min-w-[44px] flex items-center justify-center">View</a>
                                    <form method="POST" action="{{ route('logsheets.imports.destroy', $import) }}" class="inline" onsubmit="return confirm('Delete this import and ALL associated data (log sheets, details, raw rows, clearings, and the uploaded file)? This cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-200 min-h-[44px] min-w-[44px] flex items-center justify-center">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-16 text-center">
                                <svg class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                </svg>
                                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">No imports yet. Upload an Excel file to get started.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="border-t border-zinc-200 bg-zinc-50/70 dark:border-zinc-800 dark:bg-zinc-900/50">
                        <td class="px-6 py-3 text-left font-semibold text-zinc-900 dark:text-zinc-100">Grand Total</td>
                        <td class="px-6 py-3 text-right font-mono font-semibold text-zinc-900 dark:text-zinc-100">&#8377;{{ number_format($grandTotal, 2) }}</td>
                        <td class="px-6 py-3"></td>
                        <td class="px-6 py-3"></td>
                        <td class="px-6 py-3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Cards (mobile) -->
        <div class="sm:hidden divide-y divide-zinc-200 dark:divide-zinc-800">
            @forelse($imports as $import)
                <div class="p-4">
                    <div class="flex flex-col gap-1 mb-3">
                        <span class="font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $import->date_from?->format('j M Y') ?? '—' }} &rarr; {{ $import->date_to?->format('j M Y') ?? '—' }}
                        </span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $import->original_filename ?? '—' }}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Cleared {{ (int) ($import->cleared_count ?? 0) }} of {{ (int) ($import->log_sheet_numbers_count ?? 0) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                            <span class="{{ ($import->total_amount ?? 0) > 0 ? 'text-red-600 dark:text-red-400' : '' }}">&#8377;{{ number_format($import->total_amount ?? 0, 2) }}</span>
                        </span>
<div class="flex gap-2">
                            @php
                                $invalidCount = $import->invalid_count ?? 0;
                            @endphp
                            @if($invalidCount > 0)
                                <a href="{{ route('logsheets.imports.show', ['import' => $import->id]) }}#invalid-rows" class="text-red-600 hover:underline dark:text-red-400 min-h-[44px] min-w-[44px] flex items-center justify-center px-3 text-xs font-medium">
                                    <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    {{ $invalidCount }} invalid
                                </a>
                            @else
                                <span class="text-xs text-emerald-600 dark:text-emerald-400 min-h-[44px] min-w-[44px] flex items-center justify-center px-3">
                                    <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    No invalid rows
                                </span>
                            @endif
                            <a href="{{ route('logsheets.imports.show', $import) }}" class="text-brand-600 hover:underline dark:text-brand-500 min-h-[44px] min-w-[44px] flex items-center justify-center px-3">View</a>
                            <form method="POST" action="{{ route('logsheets.imports.destroy', $import) }}" class="inline" onsubmit="return confirm('Delete this import and ALL associated data (log sheets, details, raw rows, clearings, and the uploaded file)? This cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-200 min-h-[44px] min-w-[44px] flex items-center justify-center px-3 text-xs">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-6 py-16 text-center">
                    <svg class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">No imports yet. Upload an Excel file to get started.</p>
                </div>
            @endforelse
        </div>

        <!-- Pagination -->
        <div class="flex items-center justify-between border-t border-zinc-200 px-6 py-3 dark:border-zinc-800">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $imports->firstItem() ?? 0 }}-{{ $imports->lastItem() ?? 0 }} of {{ $imports->total() }}</p>
            {{ $imports->links() }}
        </div>
    </div>
</div>
@endsection