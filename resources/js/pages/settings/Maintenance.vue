<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import {
    Wrench,
    ShieldCheck,
    CheckCircle2,
    XCircle,
    Clock,
    RefreshCw,
    AlertTriangle,
    Calendar,
    Eye,
    Edit3,
    RotateCcw,
    Power,
    FileText,
    X,
} from '@lucide/vue';

interface VerificationTest {
    id: string;
    title: string;
    status: 'passed' | 'failed';
    latency_ms?: number | null;
    message: string;
}

interface VerificationResult {
    all_passed: boolean;
    verified_at: string;
    tests: VerificationTest[];
}

interface MaintenanceAuditLog {
    id: number;
    event: string;
    description: string;
    user_email: string;
    outcome: string;
    metadata?: Record<string, any> | null;
    created_at: string;
}

const props = defineProps<{
    status: {
        enabled: boolean;
        reason?: string;
        scheduled_start_at?: string | null;
        duration_hours?: number;
        duration_minutes?: number;
        duration_total_minutes?: number;
        expected_return_time?: string | null;
        expected_end_at?: string | null;
        is_manual_override?: boolean;
        enabled_at?: string | null;
        enabled_by?: string | null;
    };
    isSuperAdmin: boolean;
    recentLogs?: MaintenanceAuditLog[];
}>();

/**
 * Format a Date object into local YYYY-MM-DDTHH:mm for <input type="datetime-local">
 */
const toLocalDateTimeInput = (dateInput?: string | Date | null): string => {
    const d = dateInput ? new Date(dateInput) : new Date();
    const target = Number.isNaN(d.getTime()) ? new Date() : d;
    const year = target.getFullYear();
    const month = String(target.getMonth() + 1).padStart(2, '0');
    const day = String(target.getDate()).padStart(2, '0');
    const hours = String(target.getHours()).padStart(2, '0');
    const minutes = String(target.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
};

const nowTick = ref<number>(Date.now());
let countdownInterval: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    countdownInterval = setInterval(() => {
        nowTick.value = Date.now();
    }, 1000);
});

onUnmounted(() => {
    if (countdownInterval) {
        clearInterval(countdownInterval);
    }
});

const minDateTimeLocal = computed(() => toLocalDateTimeInput(new Date(nowTick.value - 60_000)));

const enableForm = useForm({
    reason: props.status.reason || 'Database updates, system upgrades, deployment, or scheduled server maintenance.',
    scheduled_start_at: props.status.scheduled_start_at
        ? toLocalDateTimeInput(props.status.scheduled_start_at)
        : toLocalDateTimeInput(new Date()),
    duration_hours: typeof props.status.duration_hours === 'number' ? props.status.duration_hours : 0,
    duration_minutes: typeof props.status.duration_minutes === 'number' ? props.status.duration_minutes : 30,
    expected_return_time: props.status.expected_return_time || '',
    is_manual_override: Boolean(props.status.is_manual_override),
});

const validationErrors = ref<{
    reason?: string;
    scheduled_start_at?: string;
    duration?: string;
    expected_return_time?: string;
}>({});

const normalizeDurationInputs = () => {
    let hrs = Number(enableForm.duration_hours);
    let mins = Number(enableForm.duration_minutes);

    if (Number.isNaN(hrs) || hrs < 0) hrs = 0;
    if (hrs > 168) hrs = 168;

    if (Number.isNaN(mins) || mins < 0) mins = 0;
    if (mins > 59) mins = 59;

    enableForm.duration_hours = Math.floor(hrs);
    enableForm.duration_minutes = Math.floor(mins);
};

const formatDurationLabel = (hours: number, minutes: number): string => {
    const hrs = Math.max(0, Math.floor(Number(hours) || 0));
    const mins = Math.max(0, Math.min(59, Math.floor(Number(minutes) || 0)));
    const parts: string[] = [];
    if (hrs > 0) {
        parts.push(`${hrs} ${hrs === 1 ? 'hour' : 'hours'}`);
    }
    if (mins > 0 || parts.length === 0) {
        parts.push(`${mins} ${mins === 1 ? 'minute' : 'minutes'}`);
    }
    return parts.join(' ');
};

const totalDurationMinutes = computed(() => {
    const hrs = Math.max(0, Math.floor(Number(enableForm.duration_hours) || 0));
    const mins = Math.max(0, Math.min(59, Math.floor(Number(enableForm.duration_minutes) || 0)));
    return hrs * 60 + mins;
});

const calculatedEndDate = computed<Date | null>(() => {
    if (!enableForm.scheduled_start_at) return null;
    const start = new Date(enableForm.scheduled_start_at);
    if (Number.isNaN(start.getTime())) return null;
    return new Date(start.getTime() + totalDurationMinutes.value * 60_000);
});

const autoCalculatedReturnTime = computed<string>(() => {
    const end = calculatedEndDate.value;
    if (!end || totalDurationMinutes.value < 1) return '';
    const formattedEnd = end.toLocaleString('en-US', {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    });
    const durLabel = formatDurationLabel(enableForm.duration_hours, enableForm.duration_minutes);
    return `${formattedEnd} (approx. ${durLabel})`;
});

// Automatically update expected_return_time whenever scheduled_start_at or estimated duration changes
watch(
    [() => enableForm.scheduled_start_at, () => enableForm.duration_hours, () => enableForm.duration_minutes, () => enableForm.is_manual_override],
    () => {
        if (!enableForm.is_manual_override) {
            enableForm.expected_return_time = autoCalculatedReturnTime.value;
        }
    },
    { immediate: true }
);

const setStartToNow = () => {
    enableForm.scheduled_start_at = toLocalDateTimeInput(new Date());
    validationErrors.value.scheduled_start_at = undefined;
};

const applyDurationPreset = (hours: number, minutes: number) => {
    enableForm.duration_hours = hours;
    enableForm.duration_minutes = minutes;
    validationErrors.value.duration = undefined;
};

const toggleManualOverride = () => {
    enableForm.is_manual_override = !enableForm.is_manual_override;
    if (!enableForm.is_manual_override) {
        enableForm.expected_return_time = autoCalculatedReturnTime.value;
    }
};

const validateSchedulingForm = (): boolean => {
    const errs: typeof validationErrors.value = {};

    if (!enableForm.reason || !enableForm.reason.trim()) {
        errs.reason = 'Please provide a maintenance reason or notice for non-admin users.';
    }

    if (!enableForm.scheduled_start_at) {
        errs.scheduled_start_at = 'Please select a scheduled start date and time.';
    } else {
        const parsedStart = new Date(enableForm.scheduled_start_at);
        if (Number.isNaN(parsedStart.getTime())) {
            errs.scheduled_start_at = 'The scheduled start date and time is invalid.';
        } else if (parsedStart.getTime() < Date.now() - 120_000) {
            errs.scheduled_start_at = 'The scheduled start date and time cannot be in the past.';
        }
    }

    const hrs = Number(enableForm.duration_hours);
    const mins = Number(enableForm.duration_minutes);

    if (!Number.isInteger(hrs) || hrs < 0 || hrs > 168) {
        errs.duration = 'Hours must be a whole number between 0 and 168.';
    } else if (!Number.isInteger(mins) || mins < 0 || mins > 59) {
        errs.duration = 'Minutes must be a whole number between 0 and 59.';
    } else if (hrs * 60 + mins < 1) {
        errs.duration = 'Estimated duration must be at least 1 minute.';
    }

    if (enableForm.is_manual_override && (!enableForm.expected_return_time || !enableForm.expected_return_time.trim())) {
        errs.expected_return_time = 'Please enter a custom expected return time or switch back to auto-calculation.';
    }

    validationErrors.value = errs;
    return Object.keys(errs).length === 0;
};

// Confirmation Modal State
const showConfirmEnableModal = ref(false);
const isDisabling = ref(false);
const showPreview = ref(true);

const promptEnableMaintenance = () => {
    normalizeDurationInputs();
    if (!validateSchedulingForm()) {
        return;
    }
    showConfirmEnableModal.value = true;
};

const confirmAndSubmitEnable = () => {
    if (!validateSchedulingForm()) {
        showConfirmEnableModal.value = false;
        return;
    }

    enableForm.post('/settings/maintenance/enable', {
        preserveScroll: true,
        onSuccess: () => {
            showConfirmEnableModal.value = false;
        },
        onError: () => {
            showConfirmEnableModal.value = false;
        },
    });
};

const submitDisable = () => {
    isDisabling.value = true;
    router.post('/settings/maintenance/disable', {}, {
        preserveScroll: true,
        onSuccess: () => {
            verificationResult.value = null;
        },
        onFinish: () => {
            isDisabling.value = false;
        },
    });
};

// Admin Maintenance Countdown Computation
const formatCountdownHMS = (msRemaining: number): string => {
    const absMs = Math.max(0, Math.abs(msRemaining));
    const totalSeconds = Math.floor(absMs / 1000);
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    const hh = String(hours).padStart(2, '0');
    const mm = String(minutes).padStart(2, '0');
    const ss = String(seconds).padStart(2, '0');

    return days > 0 ? `${days}d ${hh}:${mm}:${ss}` : `${hh}:${mm}:${ss}`;
};

const adminCountdown = computed(() => {
    const startIso = props.status.enabled
        ? (props.status.scheduled_start_at || props.status.enabled_at)
        : enableForm.scheduled_start_at;

    const endIso = props.status.enabled && props.status.expected_end_at
        ? props.status.expected_end_at
        : calculatedEndDate.value?.toISOString();

    if (!startIso || !endIso) {
        return null;
    }

    const startMs = new Date(startIso).getTime();
    const endMs = new Date(endIso).getTime();
    const currentMs = nowTick.value;

    if (Number.isNaN(startMs) || Number.isNaN(endMs)) {
        return null;
    }

    if (currentMs < startMs) {
        const untilStartMs = startMs - currentMs;
        return {
            phase: 'scheduled' as const,
            label: 'Starts In',
            timer: formatCountdownHMS(untilStartMs),
            progressPercent: 0,
            formattedStart: new Date(startMs).toLocaleString([], {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }),
            formattedEnd: new Date(endMs).toLocaleString([], {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }),
        };
    }

    const totalWindowMs = Math.max(60_000, endMs - startMs);
    const remainingMs = endMs - currentMs;

    if (remainingMs >= 0) {
        const elapsedMs = Math.max(0, currentMs - startMs);
        const progressPercent = Math.min(100, Math.round((elapsedMs / totalWindowMs) * 100));
        return {
            phase: 'active' as const,
            label: 'Remaining Window',
            timer: formatCountdownHMS(remainingMs),
            progressPercent,
            formattedStart: new Date(startMs).toLocaleString([], {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }),
            formattedEnd: new Date(endMs).toLocaleString([], {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }),
        };
    }

    return {
        phase: 'elapsed' as const,
        label: 'Window Elapsed By',
        timer: `+${formatCountdownHMS(Math.abs(remainingMs))}`,
        progressPercent: 100,
        formattedStart: new Date(startMs).toLocaleString([], {
            month: 'short',
            day: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }),
        formattedEnd: new Date(endMs).toLocaleString([], {
            month: 'short',
            day: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }),
    };
});

// Pre-Reopening Verification
const isVerifying = ref(false);
const verificationResult = ref<VerificationResult | null>(null);
const verificationError = ref<string | null>(null);

const runVerification = async () => {
    isVerifying.value = true;
    verificationError.value = null;
    try {
        const response = await fetch('/settings/maintenance/verify', {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!response.ok) {
            throw new Error(`Server returned HTTP ${response.status}`);
        }
        const data = await response.json();
        verificationResult.value = data;
    } catch (e: any) {
        console.error('Verification check failed', e);
        verificationError.value = e?.message || 'Unable to connect to verification service.';
    } finally {
        isVerifying.value = false;
    }
};

const formatEventBadge = (event: string): { label: string; classes: string } => {
    if (event === 'system.maintenance.enabled') {
        return { label: 'Activated', classes: 'bg-amber-100 text-amber-900 border-amber-300' };
    }
    if (event === 'system.maintenance.scheduled') {
        return { label: 'Scheduled', classes: 'bg-blue-50 text-blue-800 border-blue-200' };
    }
    if (event === 'system.maintenance.disabled') {
        return { label: 'Deactivated', classes: 'bg-emerald-100 text-emerald-800 border-emerald-200' };
    }
    return { label: event, classes: 'bg-gray-100 text-gray-800 border-gray-200' };
};
</script>

<template>
    <Head title="System Maintenance | Settings" />

    <div class="flex min-w-0 flex-col gap-6">
        <!-- Maintenance Page Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-gray-200">
            <div>
                <h1 class="section-title">System Maintenance</h1>
                <p class="section-subtitle">Maintenance scheduling, live countdown, transaction protection, and system readiness checks.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    href="/settings/maintenance/recycle-bin"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 hover:text-gray-900 transition-colors"
                >
                    Recycle Bin
                </Link>
                <Link
                    href="/settings/maintenance/security-logs"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 hover:text-gray-900 transition-colors"
                >
                    Security Logs
                </Link>
                <Link
                    href="/settings/maintenance/audit-trail"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 hover:text-gray-900 transition-colors"
                >
                    System Audit Trail
                </Link>
            </div>
        </div>

        <!-- 1. Admin Maintenance Scheduling & Control Card -->
        <div class="bg-white border border-gray-200 rounded-xl p-6 space-y-5">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pb-4 border-b border-gray-100">
                <div class="flex items-center gap-2.5">
                    <div class="size-8 rounded-lg bg-[#4b5320]/10 text-[#4b5320] flex items-center justify-center">
                        <Wrench class="size-4" />
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Admin Maintenance & Scheduling</h2>
                        <p class="text-xs text-gray-500">Configure planned or immediate maintenance mode to pause non-admin operations and safeguard transactions.</p>
                    </div>
                </div>

                <!-- Status Badge & Immediate Disable -->
                <div class="flex flex-wrap items-center gap-2.5">
                    <span
                        :class="[
                            'px-2.5 py-1 rounded-full text-xs font-semibold inline-flex items-center gap-1.5',
                            status.enabled
                                ? 'bg-amber-100 text-amber-900 border border-amber-300'
                                : 'bg-emerald-100 text-emerald-800 border border-emerald-200'
                        ]"
                    >
                        <span :class="['size-1.5 rounded-full', status.enabled ? 'bg-amber-500 animate-pulse' : 'bg-emerald-500']"></span>
                        {{ status.enabled ? 'Maintenance Active' : 'Normal Operation' }}
                    </span>

                    <SpecularButton
                        v-if="status.enabled"
                        variant="secondary"
                        size="sm"
                        :radius="8"
                        :disabled="isDisabling"
                        @click="submitDisable"
                    >
                        <Power class="size-3.5 mr-1 inline text-emerald-600" />
                        {{ isDisabling ? 'Disabling...' : 'Disable Maintenance Now' }}
                    </SpecularButton>
                </div>
            </div>

            <!-- Active Maintenance Banner & Live Admin Countdown -->
            <div
                v-if="status.enabled"
                class="p-4 rounded-xl bg-amber-50/90 border border-amber-200 text-amber-950 space-y-3 text-xs"
            >
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                    <div class="space-y-1">
                        <div class="font-semibold flex items-center gap-1.5 text-amber-950">
                            <AlertTriangle class="size-4 text-amber-600 shrink-0" />
                            <span>System is currently closed to staff and visitors. Admin access remains fully active.</span>
                        </div>
                        <div class="text-amber-800">
                            Reason: <strong>{{ status.reason }}</strong>
                            <span v-if="status.expected_return_time"> • Expected Return: <strong>{{ status.expected_return_time }}</strong></span>
                        </div>
                    </div>

                    <!-- Live Admin Countdown Box -->
                    <div
                        v-if="adminCountdown"
                        class="shrink-0 flex items-center gap-3 bg-white border border-amber-200/90 px-3.5 py-2 rounded-xl shadow-xs"
                    >
                        <Clock class="size-4 text-amber-600 shrink-0" />
                        <div>
                            <div class="text-[10px] uppercase tracking-wider font-semibold text-amber-700">
                                {{ adminCountdown.label }}
                            </div>
                            <div class="font-mono text-sm font-bold text-gray-900">
                                {{ adminCountdown.timer }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Countdown Progress Bar -->
                <div v-if="adminCountdown" class="space-y-1 pt-1 border-t border-amber-200/60">
                    <div class="flex items-center justify-between text-[11px] text-amber-800">
                        <span>Scheduled Start: <strong>{{ adminCountdown.formattedStart }}</strong></span>
                        <span>Target Completion: <strong>{{ adminCountdown.formattedEnd }}</strong> ({{ adminCountdown.progressPercent }}%)</span>
                    </div>
                    <div class="w-full h-1.5 bg-amber-200/70 rounded-full overflow-hidden">
                        <div
                            class="h-full bg-[#4b5320] transition-all duration-500"
                            :style="{ width: `${adminCountdown.progressPercent}%` }"
                        ></div>
                    </div>
                </div>
            </div>

            <!-- Planned Countdown Summary Banner when Normal Operation -->
            <div
                v-else-if="adminCountdown"
                class="p-3.5 rounded-xl bg-gray-50 border border-gray-200/90 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs"
            >
                <div class="flex items-center gap-2.5 text-gray-700">
                    <Clock class="size-4 text-[#4b5320] shrink-0" />
                    <span>
                        <strong>Planned Maintenance Window:</strong>
                        {{ adminCountdown.formattedStart }} → {{ adminCountdown.formattedEnd }}
                        ({{ formatDurationLabel(enableForm.duration_hours, enableForm.duration_minutes) }})
                    </span>
                </div>
                <div class="shrink-0 inline-flex items-center gap-2 px-2.5 py-1 rounded-lg bg-white border border-gray-200 text-gray-800 font-mono text-xs font-semibold">
                    <span>{{ adminCountdown.label }}:</span>
                    <span class="text-[#4b5320]">{{ adminCountdown.timer }}</span>
                </div>
            </div>

            <!-- Enable / Schedule Form -->
            <form @submit.prevent="promptEnableMaintenance" class="space-y-4">
                <!-- Maintenance Reason -->
                <div class="space-y-1.5">
                    <label for="maintenance-reason" class="text-xs font-semibold text-gray-700">Maintenance Reason / Notice</label>
                    <input
                        id="maintenance-reason"
                        v-model="enableForm.reason"
                        type="text"
                        required
                        placeholder="e.g. Database updates, system upgrades, deployment, or scheduled server maintenance."
                        class="block w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none"
                    />
                    <p v-if="validationErrors.reason || enableForm.errors.reason" class="text-[11px] text-red-600 font-medium">
                        {{ validationErrors.reason || enableForm.errors.reason }}
                    </p>
                </div>

                <!-- Scheduling Grid: Scheduled Start + Estimated Duration (Hours & Minutes) + Expected Return Time -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <!-- 1. Scheduled Start Date & Time -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label for="scheduled-start-at" class="text-xs font-semibold text-gray-700 flex items-center gap-1">
                                <Calendar class="size-3.5 text-gray-500" />
                                <span>Scheduled Start Date &amp; Time</span>
                            </label>
                            <button
                                type="button"
                                @click="setStartToNow"
                                class="text-[11px] font-semibold text-[#4b5320] hover:underline"
                            >
                                Set to Now
                            </button>
                        </div>
                        <input
                            id="scheduled-start-at"
                            v-model="enableForm.scheduled_start_at"
                            type="datetime-local"
                            :min="minDateTimeLocal"
                            required
                            class="block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none"
                        />
                        <p v-if="validationErrors.scheduled_start_at || (enableForm.errors as any).scheduled_start_at" class="text-[11px] text-red-600 font-medium">
                            {{ validationErrors.scheduled_start_at || (enableForm.errors as any).scheduled_start_at }}
                        </p>
                        <p v-else class="text-[11px] text-gray-500">
                            Select current or future date &amp; time for maintenance start.
                        </p>
                    </div>

                    <!-- 2. Estimated Duration: Numeric Hours & Minutes (0-59) -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-semibold text-gray-700">Estimated Duration</label>
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    @click="applyDurationPreset(0, 15)"
                                    class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-gray-100 hover:bg-gray-200 text-gray-700 transition-colors"
                                >
                                    15m
                                </button>
                                <button
                                    type="button"
                                    @click="applyDurationPreset(0, 30)"
                                    class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-gray-100 hover:bg-gray-200 text-gray-700 transition-colors"
                                >
                                    30m
                                </button>
                                <button
                                    type="button"
                                    @click="applyDurationPreset(1, 0)"
                                    class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-gray-100 hover:bg-gray-200 text-gray-700 transition-colors"
                                >
                                    1h
                                </button>
                                <button
                                    type="button"
                                    @click="applyDurationPreset(2, 0)"
                                    class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-gray-100 hover:bg-gray-200 text-gray-700 transition-colors"
                                >
                                    2h
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5">
                            <div class="relative">
                                <input
                                    id="duration-hours"
                                    v-model.number="enableForm.duration_hours"
                                    type="number"
                                    min="0"
                                    max="168"
                                    step="1"
                                    @blur="normalizeDurationInputs"
                                    aria-label="Duration Hours"
                                    class="block w-full rounded-xl border border-gray-200 bg-white pl-3 pr-12 py-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none"
                                />
                                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[11px] font-medium text-gray-400">
                                    Hours
                                </span>
                            </div>
                            <div class="relative">
                                <input
                                    id="duration-minutes"
                                    v-model.number="enableForm.duration_minutes"
                                    type="number"
                                    min="0"
                                    max="59"
                                    step="1"
                                    @blur="normalizeDurationInputs"
                                    aria-label="Duration Minutes"
                                    class="block w-full rounded-xl border border-gray-200 bg-white pl-3 pr-11 py-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none"
                                />
                                <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[11px] font-medium text-gray-400">
                                    Mins
                                </span>
                            </div>
                        </div>

                        <p
                            v-if="validationErrors.duration || (enableForm.errors as any).duration_hours || enableForm.errors.duration_minutes"
                            class="text-[11px] text-red-600 font-medium"
                        >
                            {{ validationErrors.duration || (enableForm.errors as any).duration_hours || enableForm.errors.duration_minutes }}
                        </p>
                        <p v-else class="text-[11px] text-gray-500">
                            Hours (0–168) and Minutes (0–59). Total: <strong>{{ formatDurationLabel(enableForm.duration_hours, enableForm.duration_minutes) }}</strong>.
                        </p>
                    </div>

                    <!-- 3. Expected Return Time (Auto-calculated + Manual Override) -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label for="expected-return-time" class="text-xs font-semibold text-gray-700 flex items-center gap-1.5">
                                <span>Expected Return Time</span>
                                <span
                                    :class="[
                                        'px-1.5 py-0.5 rounded text-[10px] font-semibold',
                                        enableForm.is_manual_override
                                            ? 'bg-amber-100 text-amber-800'
                                            : 'bg-emerald-50 text-emerald-700'
                                    ]"
                                >
                                    {{ enableForm.is_manual_override ? 'Manual Override' : 'Auto-Calculated' }}
                                </span>
                            </label>
                            <button
                                type="button"
                                @click="toggleManualOverride"
                                class="inline-flex items-center gap-1 text-[11px] font-semibold text-[#4b5320] hover:underline"
                            >
                                <RotateCcw v-if="enableForm.is_manual_override" class="size-3" />
                                <Edit3 v-else class="size-3" />
                                <span>{{ enableForm.is_manual_override ? 'Reset to Auto' : 'Manual Override' }}</span>
                            </button>
                        </div>

                        <input
                            id="expected-return-time"
                            v-model="enableForm.expected_return_time"
                            type="text"
                            :readonly="!enableForm.is_manual_override"
                            placeholder="Automatically calculated from start + duration"
                            :class="[
                                'block w-full rounded-xl border px-3 py-2 text-xs focus:outline-none',
                                enableForm.is_manual_override
                                    ? 'border-amber-300 bg-white text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320]'
                                    : 'border-gray-200 bg-gray-50 text-gray-800 cursor-default'
                            ]"
                        />

                        <p v-if="validationErrors.expected_return_time || enableForm.errors.expected_return_time" class="text-[11px] text-red-600 font-medium">
                            {{ validationErrors.expected_return_time || enableForm.errors.expected_return_time }}
                        </p>
                        <p v-else class="text-[11px] text-gray-500">
                            {{ enableForm.is_manual_override ? 'Custom return time active. Click "Reset to Auto" to recalculate.' : 'Calculated from Scheduled Start + Estimated Duration.' }}
                        </p>
                    </div>
                </div>

                <!-- Transaction Safeguard Indicator -->
                <div class="rounded-xl bg-gray-50 border border-gray-200/80 p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs text-gray-600">
                    <div class="flex items-center gap-2">
                        <ShieldCheck class="size-4 text-[#4b5320] shrink-0" />
                        <span><strong>Transaction Safeguards Active:</strong> In-progress sales POS, inventory deductions, and procurement records complete atomically without partial persistence.</span>
                    </div>
                    <button
                        type="button"
                        @click="showPreview = !showPreview"
                        class="shrink-0 inline-flex items-center gap-1.5 text-xs font-semibold text-[#4b5320] hover:underline"
                    >
                        <Eye class="size-3.5" />
                        <span>{{ showPreview ? 'Hide Notice Preview' : 'Show Notice Preview' }}</span>
                    </button>
                </div>

                <!-- Maintenance Notice Preview (What Non-Admin Users Will See) -->
                <div v-if="showPreview" class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 sm:p-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-xs font-semibold text-gray-700">
                            <Eye class="size-4 text-[#4b5320]" />
                            <span>Maintenance Notice Preview (Non-Admin View)</span>
                        </div>
                        <span class="text-[11px] text-gray-500">Live preview of the 503 screen displayed to Staff and visitors</span>
                    </div>

                    <div class="bg-white border border-gray-200/90 rounded-xl p-6 text-center relative overflow-hidden max-w-lg mx-auto shadow-xs">
                        <div class="absolute top-0 left-0 right-0 h-1 bg-[#4b5320]"></div>

                        <div class="mx-auto size-14 rounded-xl bg-amber-50 border border-amber-200/80 flex items-center justify-center text-amber-600 mb-4">
                            <Wrench class="size-6" />
                        </div>

                        <h3 class="text-lg font-semibold text-gray-900 tracking-tight">
                            System Under Maintenance
                        </h3>
                        <p class="text-xs text-gray-600 mt-1.5 font-medium leading-relaxed max-w-md mx-auto">
                            MRP ElectroDesk is temporarily unavailable while we perform system maintenance.
                        </p>

                        <div v-if="enableForm.reason" class="mt-3.5 mx-auto max-w-md rounded-xl bg-gray-50 border border-gray-200/80 px-3.5 py-2.5 text-xs text-gray-700">
                            <span class="font-semibold text-gray-900">Maintenance Notice:</span>
                            <span class="ml-1">{{ enableForm.reason }}</span>
                        </div>

                        <div v-if="enableForm.expected_return_time" class="mt-3.5 inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-amber-50 border border-amber-200 rounded-full text-[11px] font-semibold text-amber-900">
                            <Clock class="size-3.5 text-amber-600 shrink-0" />
                            <span>Expected Return: <strong class="text-amber-950 font-semibold">{{ enableForm.expected_return_time }}</strong></span>
                        </div>

                        <div class="mt-3.5 flex justify-center">
                            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-gray-50 border border-gray-200/80 text-gray-700 font-semibold text-[11px]">
                                <ShieldCheck class="size-3.5 text-[#4b5320]" />
                                <span>Controlled Maintenance Procedure Active</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-wrap items-center justify-end gap-2.5 pt-1">
                    <SpecularButton
                        v-if="status.enabled"
                        type="button"
                        variant="secondary"
                        size="md"
                        :radius="10"
                        :disabled="isDisabling"
                        @click="submitDisable"
                    >
                        <CheckCircle2 class="size-4 mr-1.5 inline text-emerald-600" />
                        {{ isDisabling ? 'Reopening...' : 'Immediately Disable Maintenance' }}
                    </SpecularButton>

                    <SpecularButton
                        type="submit"
                        :variant="status.enabled ? 'secondary' : 'brand'"
                        size="md"
                        :radius="10"
                        :disabled="enableForm.processing"
                    >
                        <Wrench class="size-4 mr-1.5 inline" />
                        {{ status.enabled ? 'Update Maintenance Schedule' : 'Enable Maintenance Mode' }}
                    </SpecularButton>
                </div>
            </form>
        </div>

        <!-- 2. Verify Before Reopening Card -->
        <div class="bg-white border border-gray-200 rounded-xl p-6 space-y-4">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pb-4 border-b border-gray-100">
                <div class="flex items-center gap-2.5">
                    <div class="size-8 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
                        <ShieldCheck class="size-4" />
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Verify Before Reopening</h2>
                        <p class="text-xs text-gray-500">Check database connectivity, login, device verification, sales POS, and stock deductions before reopening the platform.</p>
                    </div>
                </div>

                <SpecularButton
                    variant="brand"
                    size="sm"
                    :radius="8"
                    @click="runVerification"
                    :disabled="isVerifying"
                >
                    <RefreshCw :class="['size-3.5 mr-1 inline', { 'animate-spin': isVerifying }]" />
                    <span>{{ isVerifying ? 'Testing Functions...' : 'Run System Health Verification' }}</span>
                </SpecularButton>
            </div>

            <!-- Error State if request fails -->
            <div v-if="verificationError" class="p-3.5 rounded-xl border bg-red-50 border-red-200 text-red-900 text-xs flex items-center gap-2">
                <AlertTriangle class="size-4 text-red-600 shrink-0" />
                <span>Verification check failed: {{ verificationError }}</span>
            </div>

            <!-- Verification Results Panel -->
            <div v-if="verificationResult" class="space-y-3 pt-1">
                <div
                    :class="[
                        'p-3.5 rounded-xl border flex items-center justify-between text-xs font-semibold',
                        verificationResult.all_passed
                            ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
                            : 'bg-red-50 border-red-200 text-red-900'
                    ]"
                >
                    <div class="flex items-center gap-2">
                        <CheckCircle2 v-if="verificationResult.all_passed" class="size-4.5 text-emerald-600" />
                        <AlertTriangle v-else class="size-4.5 text-red-600" />
                        <span>{{ verificationResult.all_passed ? 'All Critical Systems Operational & Verified' : 'One or More Functions Failed Verification' }}</span>
                    </div>
                    <span class="text-[11px] text-gray-500 font-normal">Verified: {{ verificationResult.verified_at }}</span>
                </div>

                <!-- Individual Tests Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div
                        v-for="test in verificationResult.tests"
                        :key="test.id"
                        class="rounded-xl border border-gray-200 bg-white p-3 text-xs space-y-1"
                    >
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-gray-900">{{ test.title }}</span>
                            <span
                                :class="[
                                    'px-2 py-0.5 rounded-full text-[10px] font-semibold inline-flex items-center gap-1',
                                    test.status === 'passed' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'
                                ]"
                            >
                                <CheckCircle2 v-if="test.status === 'passed'" class="size-3" />
                                <XCircle v-else class="size-3" />
                                {{ test.status }}
                            </span>
                        </div>
                        <p class="text-[11px] text-gray-600 leading-normal">{{ test.message }}</p>
                    </div>
                </div>

                <!-- Reopen Action after verification passes -->
                <div v-if="status.enabled && verificationResult.all_passed" class="pt-4 flex justify-end">
                    <SpecularButton
                        variant="brand"
                        size="md"
                        :radius="10"
                        :disabled="isDisabling"
                        @click="submitDisable"
                    >
                        <CheckCircle2 class="size-4 mr-1.5 inline" />
                        Verify &amp; Reopen MRP ElectroDesk
                    </SpecularButton>
                </div>
            </div>

            <div v-else class="rounded-xl border border-dashed border-gray-200 p-5 text-center text-xs text-gray-500">
                Click <strong>"Run System Health Verification"</strong> above to test all critical components prior to reopening the platform.
            </div>
        </div>

        <!-- 3. Recent Maintenance Audit Trail Activity -->
        <div class="bg-white border border-gray-200 rounded-xl p-6 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-gray-100">
                <div class="flex items-center gap-2.5">
                    <div class="size-8 rounded-lg bg-gray-100 text-gray-700 flex items-center justify-center">
                        <FileText class="size-4" />
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Maintenance Audit Log</h2>
                        <p class="text-xs text-gray-500">Recorded maintenance activations, deactivations, and schedule updates in the System Audit Trail.</p>
                    </div>
                </div>
                <Link
                    href="/settings/maintenance/audit-trail"
                    class="text-xs font-semibold text-[#4b5320] hover:underline"
                >
                    View Full System Audit Trail →
                </Link>
            </div>

            <div v-if="recentLogs && recentLogs.length > 0" class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 text-xs font-semibold">
                            <th class="pb-2.5">Timestamp</th>
                            <th class="pb-2.5">Admin</th>
                            <th class="pb-2.5">Action</th>
                            <th class="pb-2.5">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-xs">
                        <tr v-for="log in recentLogs" :key="log.id" class="hover:bg-gray-50/70">
                            <td class="py-3 font-mono text-gray-500 whitespace-nowrap pr-4">{{ log.created_at }}</td>
                            <td class="py-3 font-semibold text-gray-900 whitespace-nowrap pr-4">{{ log.user_email }}</td>
                            <td class="py-3 whitespace-nowrap pr-4">
                                <span
                                    :class="[
                                        'px-2 py-0.5 rounded-full text-[11px] font-semibold border',
                                        formatEventBadge(log.event).classes
                                    ]"
                                >
                                    {{ formatEventBadge(log.event).label }}
                                </span>
                            </td>
                            <td class="py-3 text-gray-600">{{ log.description }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="rounded-xl border border-dashed border-gray-200 p-4 text-center text-xs text-gray-500">
                No maintenance audit events recorded yet. All activations, deactivations, and schedule updates will appear here.
            </div>
        </div>

        <!-- Confirmation Modal Before Enabling / Updating Maintenance Mode -->
        <div
            v-if="showConfirmEnableModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs"
            role="dialog"
            aria-modal="true"
        >
            <div class="w-full max-w-lg rounded-2xl bg-white border border-gray-200 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 bg-amber-50/60">
                    <div class="flex items-center gap-2.5">
                        <div class="size-8 rounded-lg bg-amber-100 text-amber-800 flex items-center justify-center">
                            <AlertTriangle class="size-4" />
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">
                                {{ status.enabled ? 'Confirm Maintenance Schedule Update' : 'Confirm Enabling Maintenance Mode' }}
                            </h3>
                            <p class="text-[11px] text-gray-500">Please review the maintenance window details before proceeding.</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="showConfirmEnableModal = false"
                        class="text-gray-400 hover:text-gray-600 p-1 rounded-lg"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="p-6 space-y-4 text-xs">
                    <p class="text-gray-600 leading-relaxed">
                        Enabling Maintenance Mode will immediately display the maintenance notice to all <strong>non-admin users (Staff and visitors)</strong> and pause non-admin operations. Administrator access remains active.
                    </p>

                    <div class="rounded-xl bg-gray-50 border border-gray-200/90 p-3.5 space-y-2">
                        <div class="flex justify-between gap-4">
                            <span class="text-gray-500 font-medium">Scheduled Start:</span>
                            <span class="font-semibold text-gray-900">
                                {{ enableForm.scheduled_start_at ? new Date(enableForm.scheduled_start_at).toLocaleString() : 'Immediately' }}
                            </span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-gray-500 font-medium">Estimated Duration:</span>
                            <span class="font-semibold text-gray-900">
                                {{ formatDurationLabel(enableForm.duration_hours, enableForm.duration_minutes) }}
                            </span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-gray-500 font-medium">Expected Return Time:</span>
                            <span class="font-semibold text-amber-900">
                                {{ enableForm.expected_return_time }}
                            </span>
                        </div>
                        <div class="pt-1.5 border-t border-gray-200/70">
                            <span class="text-gray-500 font-medium block mb-0.5">Maintenance Notice:</span>
                            <span class="font-semibold text-gray-800">{{ enableForm.reason }}</span>
                        </div>
                    </div>

                    <div class="flex items-start gap-2 rounded-xl bg-emerald-50/70 border border-emerald-200 px-3.5 py-2.5 text-[11px] text-emerald-900">
                        <ShieldCheck class="size-4 text-emerald-600 shrink-0 mt-0.5" />
                        <span>All ongoing database transactions will complete safely without data loss, and this action will be logged in the System Audit Trail.</span>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 bg-gray-50 border-t border-gray-100">
                    <button
                        type="button"
                        @click="showConfirmEnableModal = false"
                        class="px-3.5 py-2 rounded-xl border border-gray-200 bg-white text-xs font-semibold text-gray-700 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <SpecularButton
                        type="button"
                        variant="brand"
                        size="sm"
                        :radius="10"
                        :disabled="enableForm.processing"
                        @click="confirmAndSubmitEnable"
                    >
                        <Wrench class="size-3.5 mr-1.5 inline" />
                        {{ enableForm.processing ? 'Enabling...' : (status.enabled ? 'Confirm Update' : 'Confirm & Enable Maintenance') }}
                    </SpecularButton>
                </div>
            </div>
        </div>
    </div>
</template>
