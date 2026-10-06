// Run with: node --test tests/js/queue-alerts.test.mjs
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { decide, messageFor, stateFor, stateKey } from '../../resources/js/queue-alerts.js';

const live = {
    status: 'live',
    up_next: ['a1','a2','a3','a4'],
    courts: [{ id: 'a5', court: 2 }],
    waiting: [{ id: 'a9', position: 3, estimate: 12 }],
    on_break: ['a7'],
    players: ['a1','a2','a3','a4','a5','a7','a9'],
};

test('stateFor finds each state', () => {
    assert.deepEqual(stateFor(live, 'a5'), { kind: 'court', court: 2 });
    assert.deepEqual(stateFor(live, 'a1'), { kind: 'upnext' });
    assert.deepEqual(stateFor(live, 'a9'), { kind: 'waiting', position: 3, estimate: 12 });
    assert.deepEqual(stateFor(live, 'a7'), { kind: 'break' });
    assert.equal(stateFor(live, 'zz'), null);
    assert.equal(stateFor(live, null), null);
});

test('alerts only on a transition into up next or a court', () => {
    assert.equal(decide('waiting', { kind: 'upnext' }).alert, true);
    assert.equal(decide('upnext', { kind: 'court', court: 2 }).alert, true);
    assert.equal(decide('court:1', { kind: 'court', court: 2 }).alert, true);
    assert.equal(decide('none', { kind: 'upnext' }).alert, true);
});

test('does not repeat for the same state or on first sight', () => {
    assert.equal(decide('upnext', { kind: 'upnext' }).alert, false);
    assert.equal(decide('court:2', { kind: 'court', court: 2 }).alert, false);
    assert.equal(decide(null, { kind: 'upnext' }).alert, false);
});

test('does not alert when dropping back to waiting or none', () => {
    assert.equal(decide('upnext', { kind: 'waiting', position: 1, estimate: 5 }).alert, false);
    assert.equal(decide('court:2', null).alert, false);
});

test('an up next that is voided and re-staged alerts again', () => {
    const first = decide('waiting', { kind: 'upnext' });
    const back = decide(first.key, { kind: 'waiting', position: 1, estimate: 5 });
    assert.equal(decide(back.key, { kind: 'upnext' }).alert, true);
});

test('messages', () => {
    assert.equal(messageFor({ kind: 'upnext' }).title, "You're up next!");
    assert.equal(messageFor({ kind: 'court', court: 4 }).title, 'Go to court 4');
    assert.equal(messageFor({ kind: 'waiting' }), null);
    assert.equal(stateKey(null), 'none');
});
