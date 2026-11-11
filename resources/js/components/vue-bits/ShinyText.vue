<script setup lang="ts">
// Adapted from Vue Bits ShinyText (TS + Tailwind). See LICENSE.md and README.md in this directory.
import {
    Motion,
    useAnimationFrame,
    useMotionValue,
    useTransform,
} from 'motion-v';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

interface ShinyTextProps {
    text: string;
    disabled?: boolean;
    speed?: number;
    className?: string;
    color?: string;
    shineColor?: string;
    spread?: number;
    yoyo?: boolean;
    pauseOnHover?: boolean;
    direction?: 'left' | 'right';
    delay?: number;
    /**
     * A CSS gradient for the text instead of the flat `color` (e.g. a brand gradient); the shine
     * sweeps over it.
     */
    baseGradient?: string;
    /** The original's display class, as a default a page can replace (e.g. `block`). */
    baseClassName?: string;
}

const props = withDefaults(defineProps<ShinyTextProps>(), {
    disabled: false,
    speed: 2,
    className: '',
    color: '#b5b5b5',
    shineColor: '#ffffff',
    spread: 120,
    yoyo: false,
    pauseOnHover: false,
    direction: 'left',
    delay: 0,
    baseGradient: undefined,
    baseClassName: 'inline-block',
});

const isPaused = ref(false);
const progress = useMotionValue(0);
const elapsedRef = ref(0);
const lastTimeRef = ref<number | null>(null);
const directionRef = ref(props.direction === 'left' ? 1 : -1);

// "Reduce motion": no endless sweep, just the colored text.
const reduceMotion = ref(false);
let motionQuery: MediaQueryList | null = null;
const onMotionChange = () => {
    reduceMotion.value = !!motionQuery?.matches;
};
onMounted(() => {
    motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    onMotionChange();
    motionQuery.addEventListener('change', onMotionChange);
});
onUnmounted(() => motionQuery?.removeEventListener('change', onMotionChange));

const animationDuration = computed(() => props.speed * 1000);
const delayDuration = computed(() => props.delay * 1000);

useAnimationFrame((time) => {
    if (props.disabled || reduceMotion.value || isPaused.value) {
        lastTimeRef.value = null;
        return;
    }

    if (lastTimeRef.value === null) {
        lastTimeRef.value = time;
        return;
    }

    const deltaTime = time - lastTimeRef.value;
    lastTimeRef.value = time;

    elapsedRef.value += deltaTime;

    // Animation goes from 0 to 100
    if (props.yoyo) {
        const cycleDuration = animationDuration.value + delayDuration.value;
        const fullCycle = cycleDuration * 2;
        const cycleTime = elapsedRef.value % fullCycle;

        if (cycleTime < animationDuration.value) {
            // Forward animation: 0 -> 100
            const p = (cycleTime / animationDuration.value) * 100;
            progress.set(directionRef.value === 1 ? p : 100 - p);
        } else if (cycleTime < cycleDuration) {
            // Delay at end
            progress.set(directionRef.value === 1 ? 100 : 0);
        } else if (cycleTime < cycleDuration + animationDuration.value) {
            // Reverse animation: 100 -> 0
            const reverseTime = cycleTime - cycleDuration;
            const p = 100 - (reverseTime / animationDuration.value) * 100;
            progress.set(directionRef.value === 1 ? p : 100 - p);
        } else {
            // Delay at start
            progress.set(directionRef.value === 1 ? 0 : 100);
        }
    } else {
        const cycleDuration = animationDuration.value + delayDuration.value;
        const cycleTime = elapsedRef.value % cycleDuration;

        if (cycleTime < animationDuration.value) {
            // Animation phase: 0 -> 100
            const p = (cycleTime / animationDuration.value) * 100;
            progress.set(directionRef.value === 1 ? p : 100 - p);
        } else {
            // Delay phase - hold at end (shine off-screen)
            progress.set(directionRef.value === 1 ? 100 : 0);
        }
    }
});

watch(
    () => props.direction,
    () => {
        directionRef.value = props.direction === 'left' ? 1 : -1;
        elapsedRef.value = 0;
        progress.set(0);
    },
    {
        immediate: true,
    },
);

// Two layers: the moving shine (transparent outside its band, so it can sit off-screen) over the
// fixed base color or gradient. Only the shine layer's position changes.
const backgroundPosition = useTransform(
    progress,
    (p) => `${150 - p * 2}% center, 0% 0%`,
);

const handleMouseEnter = () => {
    if (props.pauseOnHover) isPaused.value = true;
};

const handleMouseLeave = () => {
    if (props.pauseOnHover) isPaused.value = false;
};

const gradientStyle = computed(() => {
    const shine = `linear-gradient(${props.spread}deg, transparent 0%, transparent 35%, ${props.shineColor} 50%, transparent 65%, transparent 100%)`;
    const base =
        props.baseGradient ?? `linear-gradient(${props.color}, ${props.color})`;

    return {
        backgroundImage: `${shine}, ${base}`,
        backgroundSize: '200% 100%, 100% 100%',
        backgroundRepeat: 'no-repeat',
        WebkitBackgroundClip: 'text',
        backgroundClip: 'text',
        WebkitTextFillColor: 'transparent',
    };
});
</script>

<template>
    <Motion
        as="span"
        :class="[baseClassName, className]"
        :style="{ ...gradientStyle, backgroundPosition }"
        @mouseenter="handleMouseEnter"
        @mouseleave="handleMouseLeave"
    >
        {{ text }}
    </Motion>
</template>
