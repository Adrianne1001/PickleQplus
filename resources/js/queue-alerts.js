/**
 * Pure helpers for the public queue page: who is "me", and when to alert.
 * No DOM or Alpine here, so they can be tested with `node --test tests/js`.
 *
 * `live` is the ids-only state the server sends (see App\Livewire\Public\Queue::$live).
 */

/**
 * Where is this player right now?
 * Returns {kind: 'court', court}, {kind: 'upnext'}, {kind: 'waiting', position, estimate},
 * {kind: 'break'}, or null when they aren't in the live queue.
 */
export function stateFor(live, me) {
    if (!me || !live) {
        return null;
    }

    const court = (live.courts ?? []).find((c) => c.id === me);
    if (court) {
        return { kind: 'court', court: court.court };
    }

    if ((live.up_next ?? []).includes(me)) {
        return { kind: 'upnext' };
    }

    const waiting = (live.waiting ?? []).find((w) => w.id === me);
    if (waiting) {
        return { kind: 'waiting', position: waiting.position, estimate: waiting.estimate };
    }

    if ((live.on_break ?? []).includes(me)) {
        return { kind: 'break' };
    }

    return null;
}

/** A short, comparable key for a state, e.g. "court:3", "upnext", "waiting". */
export function stateKey(state) {
    if (!state) {
        return 'none';
    }

    return state.kind === 'court' ? `court:${state.court}` : state.kind;
}

/** Only these two states are worth an alert. */
export function isAlertable(state) {
    return state?.kind === 'upnext' || state?.kind === 'court';
}

/** Banner and notification wording, or null for non-alert states. */
export function messageFor(state) {
    if (state?.kind === 'upnext') {
        return { title: "You're up next!", body: 'Get ready, you are in the next match.' };
    }

    if (state?.kind === 'court') {
        return { title: `Go to court ${state.court}`, body: 'Your match is ready to start.' };
    }

    return null;
}

/**
 * Decide whether the state change should alert. `previousKey` is what this tab
 * last saw (kept in sessionStorage so a re-render or reload doesn't repeat it).
 * The first observation (no previous key) never alerts: the banner still shows,
 * but vibrate/notification need a real transition.
 *
 * @returns {{alert: boolean, key: string}}
 */
export function decide(previousKey, state) {
    const key = stateKey(state);

    return { alert: previousKey !== null && previousKey !== key && isAlertable(state), key };
}
