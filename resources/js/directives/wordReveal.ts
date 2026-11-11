import { animate, stagger } from 'motion';
import { splitText } from 'motion-plus';
import type { Directive } from 'vue';

/**
 * v-word-reveal: the element's words fade in and slide up one after another, with a spring,
 * as soon as the element mounts (never on scroll).
 *
 *   <h2 v-word-reveal class="...">About us</h2>
 *
 * The element keeps its tag, classes and nested markup (bold text, highlighted phrases, icons,
 * line breaks). Each text run is split into words with splitText() from motion-plus; the words
 * then wrap like normal text, so the layout still reflows on resize, and become plain text again
 * once the animation has finished. Runs once per element,
 * waits for web fonts first (no layout shift), and stops when the element unmounts. With
 * "reduce motion" turned on, the text is simply shown.
 *
 * When every word is in place the element fires `word-reveal-complete`
 * (`@word-reveal-complete="..."`). Gradient text inside the element: see fillWords().
 */

export const COMPLETE_EVENT = 'word-reveal-complete';

interface RevealState {
    cancelled: boolean;
    controls?: ReturnType<typeof animate>;
}

const states = new WeakMap<HTMLElement, RevealState>();

const FONT_WAIT_MS = 1500;

/** Elements whose content is not text to reveal. */
const SKIP = new Set(['BR', 'IMG', 'SVG', 'svg', 'SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'SELECT']);

/** Collapses template whitespace (spaces, tabs, new lines) but keeps non-breaking spaces. */
const collapse = (text: string) => text.replace(/[ \t\n\r\f]+/g, ' ');

const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Splits every text run inside `root` into word spans, in reading order. Nested elements are
 * walked rather than flattened, so their styling stays on their own words; spaces around each
 * run are kept as plain text so words and punctuation read exactly as before.
 */
function splitWords(root: HTMLElement): HTMLSpanElement[] {
    const words: HTMLSpanElement[] = [];

    const walk = (parent: Node) => {
        for (const node of Array.from(parent.childNodes)) {
            if (node.nodeType === Node.ELEMENT_NODE) {
                if (!SKIP.has((node as Element).tagName)) walk(node);
                continue;
            }
            if (node.nodeType !== Node.TEXT_NODE) continue;

            const text = collapse(node.textContent ?? '');
            const trimmed = text.trim();
            if (!trimmed) continue; // whitespace between elements stays as it is

            const run = document.createElement('span');
            run.textContent = trimmed;
            if (text.startsWith(' ')) parent.insertBefore(document.createTextNode(' '), node);
            parent.insertBefore(run, node);
            if (text.endsWith(' ')) parent.insertBefore(document.createTextNode(' '), node);
            parent.removeChild(node);

            const split = splitText(run);
            // splitText groups words into line boxes measured once; unwrap them so the words wrap
            // freely when the width changes, and drop the per-letter spans to keep normal kerning.
            for (const line of split.lines) line.replaceWith(...Array.from(line.childNodes));
            for (const word of split.words) word.textContent = word.textContent ?? '';
            run.removeAttribute('aria-label'); // the words are still real text for screen readers
            words.push(...split.words);
        }
    };

    walk(root);
    return words;
}

/** Tell the page the words are all in place (e.g. to start a follow-up effect). */
const complete = (el: HTMLElement) => el.dispatchEvent(new CustomEvent(COMPLETE_EVENT));

/**
 * Gradient text: a parent's `background-clip: text` does not paint through words that are
 * fading and moving on their own, so while they animate, each word inside an element with
 * `data-reveal-fill="<css gradient>"` shows its own slice of that gradient, sized to the element.
 * Measured before the words move; the slices go away with the word spans afterwards.
 */
function fillWords(words: HTMLSpanElement[]): void {
    for (const word of words) {
        const host = word.closest<HTMLElement>('[data-reveal-fill]');
        const fill = host?.dataset.revealFill;
        if (!host || !fill) continue;
        const box = host.getBoundingClientRect();
        const at = word.getBoundingClientRect();
        Object.assign(word.style, {
            backgroundImage: fill,
            backgroundSize: `${box.width}px ${box.height}px`,
            backgroundPosition: `${box.left - at.left}px ${box.top - at.top}px`,
            backgroundRepeat: 'no-repeat',
            webkitBackgroundClip: 'text',
            backgroundClip: 'text',
            webkitTextFillColor: 'transparent',
        });
    }
}

function reveal(el: HTMLElement, state: RevealState): void {
    if (state.cancelled || !el.isConnected) return;

    const words = splitWords(el);
    el.dataset.wordReveal = 'done';
    el.style.visibility = '';
    if (!words.length) {
        complete(el);
        return;
    }
    fillWords(words);

    state.controls = animate(
        words,
        { opacity: [0, 1], y: [10, 0] },
        { type: 'spring', duration: 2, bounce: 0, delay: stagger(0.05) },
    );

    // Done: turn the words back into plain text. Inline-block words would otherwise block link
    // underlines and kerning across words; at rest they look exactly the same.
    state.controls.then(() => {
        if (state.cancelled) return;
        for (const word of words) {
            if (word.isConnected) word.replaceWith(...Array.from(word.childNodes));
        }
        state.controls = undefined;
        complete(el);
    });
}

export const vWordReveal: Directive<HTMLElement> = {
    beforeMount(el) {
        // Hidden (space kept) until the words are split and ready, so the text never flashes.
        if (el.dataset.wordReveal !== 'done' && !prefersReducedMotion()) {
            el.style.visibility = 'hidden';
        }
    },

    mounted(el) {
        if (el.dataset.wordReveal === 'done' || states.has(el)) return; // never twice

        const state: RevealState = { cancelled: false };
        states.set(el, state);

        if (prefersReducedMotion()) {
            el.dataset.wordReveal = 'done';
            queueMicrotask(() => complete(el)); // after the page's listeners are attached
            return;
        }

        // Wait for web fonts so words are measured in their final font; never wait more than
        // FONT_WAIT_MS, so a slow or failing font can not keep the text hidden.
        const fonts = (document as Document & { fonts?: FontFaceSet }).fonts;
        const fontsReady = fonts ? fonts.ready.catch(() => undefined) : Promise.resolve();
        const timeout = new Promise((resolve) => setTimeout(resolve, FONT_WAIT_MS));
        Promise.race([fontsReady, timeout]).then(() => reveal(el, state));
    },

    beforeUnmount(el) {
        const state = states.get(el);
        if (state) {
            state.cancelled = true;
            state.controls?.stop();
        }
        states.delete(el);
    },
};
