<div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-premium-sm dark:border-zinc-800 dark:bg-zinc-900" data-preview-url="{{ route('logsheets.clear.preview') }}" x-data="logsheetClear()" x-init="init()">
    <h2 class="mb-4 text-base font-semibold text-zinc-900 dark:text-zinc-100">Clear Payments</h2>

    <form id="clear-form" method="POST" action="{{ route('logsheets.clear.bulk') }}" enctype="multipart/form-data" @submit.prevent="submitForm">
        @csrf

        <!-- Chip Input -->
        <div class="space-y-3">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Log Sheet Numbers</label>
            <div class="relative">
                <div
                    class="flex flex-wrap items-center gap-2 min-h-[48px] px-3 py-2 rounded-lg border border-zinc-300 bg-white focus-within:border-brand-500 focus-within:ring-1 focus-within:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-800"
                    @click="focusInput()"
                    role="listbox"
                    aria-label="Log sheet numbers"
                >
                    <template x-for="(chip, index) in chips" :key="chip">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="chipStatusClass(chip)"
                            role="option"
                        >
                            <span x-text="chip"></span>
                            <button
                                type="button"
                                @click.stop="removeChip(index)"
                                class="flex h-4 w-4 items-center justify-center rounded-full hover:bg-black/10 dark:hover:bg-white/10"
                                aria-label="Remove"
                            >
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </span>
                    </template>
                    <input
                        type="text"
                        x-ref="input"
                        @keydown="handleKeydown($event)"
                        @input="handleInput($event)"
                        @paste="handlePaste($event)"
                        @blur="handleBlur()"
                        class="flex-1 min-w-[120px] bg-transparent border-none outline-none text-sm text-zinc-900 dark:text-zinc-100 placeholder-zinc-400"
                        placeholder="Paste or type log sheet numbers..."
                        aria-label="Log sheet numbers input"
                        autocomplete="off"
                    >
                </div>
                <input type="hidden" name="numbers" x-ref="numbersInput">
                @error('numbers')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                <span x-text="chipCountText"></span>
                <button type="button" @click="clearAllChips" class="text-brand-600 hover:text-brand-700 dark:text-brand-400">Clear all</button>
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400" x-show="chips.length >= 500">Maximum 500 numbers allowed. Excess will be ignored.</p>
        </div>

        <!-- Date Range -->
        <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">Clears every record with these log sheet numbers dated inside this period. Leave empty to clear all matching records.</p>
        <div class="grid gap-4 mt-4 sm:grid-cols-2">
            <div>
                <label for="date_from" class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Period (optional)</label>
                <input
                    type="date"
                    id="date_from"
                    name="date_from"
                    x-model="dateFrom"
                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                >
                @error('date_from')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="date_to" class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">&nbsp;</label>
                <input
                    type="date"
                    id="date_to"
                    name="date_to"
                    x-model="dateTo"
                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                >
                @error('date_to')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <!-- Check Button & Preview Results -->
        <div class="mt-4 space-y-3">
            <button
                type="button"
                @click="checkPreview()"
                :disabled="chips.length === 0 || checking"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-zinc-100 px-4 text-sm font-medium text-zinc-700 shadow-sm hover:bg-zinc-200 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
            >
                <svg x-show="checking" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span x-text="checking ? 'Checking...' : 'Check'"></span>
            </button>

            <div x-show="previewError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" x-text="previewError"></div>

            <div x-show="hasPreview" class="space-y-3">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 font-bold text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span> Will clear
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 font-bold text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-600"></span> Already cleared
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 font-bold text-red-800 dark:bg-red-900/30 dark:text-red-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-600"></span> Not found
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-1 font-bold text-zinc-800 dark:bg-zinc-900/30 dark:text-zinc-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-zinc-600"></span> Out of range
                    </span>
                </div>
                <div class="grid gap-2 sm:grid-cols-3 text-sm">
                    <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Will clear</p>
                        <p class="font-semibold text-emerald-700 dark:text-emerald-400" x-text="previewCounts.pending"></p>
                    </div>
                    <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Already cleared</p>
                        <p class="font-semibold text-amber-700 dark:text-amber-400" x-text="previewCounts.cleared"></p>
                    </div>
                    <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Not found</p>
                        <p class="font-semibold text-red-700 dark:text-red-400" x-text="previewCounts.not_found"></p>
                    </div>
                    <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800 sm:col-span-2">
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Out of range</p>
                        <p class="font-semibold text-zinc-700 dark:text-zinc-400" x-text="previewCounts.out_of_range"></p>
                    </div>
                    <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800 sm:col-span-3">
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Total to be cleared</p>
                        <p class="font-mono font-semibold text-emerald-700 dark:text-emerald-400" x-text="'₹' + formatAmount(previewTotalPending)"></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Primary Action Button -->
        <div class="mt-4">
            <button
                type="button"
                @click="openConfirmModal()"
                :disabled="pendingCount === 0 || submitting"
                class="inline-flex w-full sm:w-auto h-10 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-6 text-sm font-medium text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <svg x-show="submitting" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span x-text="submitting ? 'Clearing...' : 'Mark ' + pendingCount + ' as cleared'"></span>
            </button>
        </div>

        <!-- Result Summary (shown after submit) -->
        <div x-show="result" class="mt-4 rounded-lg border bg-zinc-50 p-4 dark:bg-zinc-800" aria-live="polite">
            <div class="flex items-center gap-2 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Done</span>
            </div>
            <div class="mt-3 grid gap-2 sm:grid-cols-3 text-sm">
                <div class="rounded-lg bg-emerald-50 px-3 py-2 dark:bg-emerald-900/20">
                    <p class="text-xs text-emerald-700 dark:text-emerald-400">Cleared</p>
                    <p class="font-semibold text-emerald-700 dark:text-emerald-400" x-text="result?.counts?.cleared"></p>
                </div>
                <div class="rounded-lg bg-amber-50 px-3 py-2 dark:bg-amber-900/20">
                    <p class="text-xs text-amber-700 dark:text-amber-400">Already cleared</p>
                    <p class="font-semibold text-amber-700 dark:text-amber-400" x-text="result?.counts?.already_cleared"></p>
                </div>
                <div class="rounded-lg bg-red-50 px-3 py-2 dark:bg-red-900/20">
                    <p class="text-xs text-red-700 dark:text-red-400">Not found</p>
                    <p class="font-semibold text-red-700 dark:text-red-400" x-text="result?.counts?.not_found"></p>
                </div>
                <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800 sm:col-span-2">
                    <p class="text-xs text-zinc-700 dark:text-zinc-400">Out of range</p>
                    <p class="font-semibold text-zinc-700 dark:text-zinc-400" x-text="result?.counts?.out_of_range"></p>
                </div>
                <div class="rounded-lg bg-emerald-50 px-3 py-2 dark:bg-emerald-900/20 sm:col-span-3">
                    <p class="text-xs text-emerald-700 dark:text-emerald-400">Total cleared</p>
                    <p class="font-mono font-semibold text-emerald-700 dark:text-emerald-400" x-text="'₹' + formatAmount(result?.total_cleared_amount)"></p>
                </div>
            </div>
        </div>
    </form>

    <!-- Confirmation Modal -->
    <div x-show="showModal" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true" role="dialog" @keydown.escape="closeModal()">
        <div class="flex min-h-full items-center justify-center p-4">
            <div x-show="showModal" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-black/50" @click="closeModal()"></div>
            <div class="relative w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                <h3 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-zinc-100">Confirm Clear</h3>
                <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">Are you sure you want to mark <strong x-text="pendingCount"></strong> log sheet(s) as cleared?</p>
                <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">Total amount: <strong class="font-mono" x-text="'₹' + formatAmount(previewTotalPending)"></strong></p>
                <div class="flex justify-end gap-3">
                    <button type="button" data-clear-cancel @click="closeModal()" class="inline-flex h-10 items-center justify-center rounded-lg border border-zinc-300 px-4 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">Cancel</button>
                    <button type="button" @click="confirmClear()" :disabled="submitting" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 text-sm font-medium text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg x-show="submitting" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-text="submitting ? 'Clearing...' : 'Confirm'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Noscript Fallback -->
    <noscript>
        <div class="mt-4 rounded-lg border border-zinc-300 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="mb-3 text-sm text-zinc-700 dark:text-zinc-300">JavaScript is disabled. Use the form below to clear log sheets.</p>
            <form method="POST" action="{{ route('logsheets.clear.bulk') }}" class="space-y-3">
                @csrf
                <div>
                    <label for="numbers_noscript" class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Log Sheet Numbers (one per line)</label>
                    <textarea
                        id="numbers_noscript"
                        name="numbers"
                        rows="5"
                        class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        required
                    ></textarea>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="date_from_noscript" class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">From Date</label>
                        <input type="date" id="date_from_noscript" name="date_from" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                    </div>
                    <div>
                        <label for="date_to_noscript" class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">To Date</label>
                        <input type="date" id="date_to_noscript" name="date_to" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                    </div>
                </div>
                <button type="submit" class="inline-flex h-10 items-center justify-center rounded-lg bg-emerald-600 px-6 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">Mark as cleared</button>
            </form>
            @if(session('clear_report'))
                <div class="mt-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="mb-2 text-sm font-medium text-zinc-900 dark:text-zinc-100">Result Summary</p>
                    <div class="grid gap-2 sm:grid-cols-3 text-sm">
                        <div class="rounded-lg bg-emerald-50 px-3 py-2 dark:bg-emerald-900/20">
                            <p class="text-xs text-emerald-700 dark:text-emerald-400">Cleared</p>
                            <p class="font-semibold" x-text="session('clear_report.counts.cleared')"></p>
                        </div>
                        <div class="rounded-lg bg-amber-50 px-3 py-2 dark:bg-amber-900/20">
                            <p class="text-xs text-amber-700 dark:text-amber-400">Already cleared</p>
                            <p class="font-semibold" x-text="session('clear_report.counts.already_cleared')"></p>
                        </div>
                        <div class="rounded-lg bg-red-50 px-3 py-2 dark:bg-red-900/20">
                            <p class="text-xs text-red-700 dark:text-red-400">Not found</p>
                            <p class="font-semibold" x-text="session('clear_report.counts.not_found')"></p>
                        </div>
                        <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800 sm:col-span-2">
                            <p class="text-xs text-zinc-700 dark:text-zinc-400">Out of range</p>
                            <p class="font-semibold" x-text="session('clear_report.counts.out_of_range')"></p>
                        </div>
                        <div class="rounded-lg bg-emerald-50 px-3 py-2 dark:bg-emerald-900/20 sm:col-span-3">
                            <p class="text-xs text-emerald-700 dark:text-emerald-400">Total cleared</p>
                            <p class="font-mono font-semibold" x-text="'₹' + session('clear_report.total_cleared_amount')"></p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </noscript>
</div>