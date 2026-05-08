import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { onEvents, sendAck } from '../../resources/js/services/eventContract.js';

// [PRE-PROD HARDENING / SYN-2 / P0-7] Pusher heartbeat client→server ack.
//
// Pins three invariants of the new sendAck() helper:
//   1. it POSTs to /api/internal/pusher-ack with the snake_case payload
//      shape that PusherAckController expects;
//   2. it is a no-op without correlation_id (server cannot dedupe);
//   3. it is wired from rawHandler so every successfully parsed envelope
//      produces exactly one ack call, AFTER the user handler ran.

describe('eventContract — sendAck (SYN-2)', () => {
    let postSpy;
    let originalAxios;

    beforeEach(() => {
        originalAxios = window.axios;
        postSpy = vi.fn().mockReturnValue({ catch: () => {} });
        window.axios = { post: postSpy };
    });

    afterEach(() => {
        if (originalAxios !== undefined) {
            window.axios = originalAxios;
        } else {
            delete window.axios;
        }
    });

    it('POSTs the expected snake_case envelope shape', () => {
        const raw = {
            version: 1,
            type: 'order.created',
            correlation_id: 'corr-123',
            branch_id: 7,
            domain_event_id: 99,
            payload: { hello: 'world' },
        };

        sendAck(raw, 'private-branch.7');

        expect(postSpy).toHaveBeenCalledTimes(1);
        const [url, body, options] = postSpy.mock.calls[0];
        expect(url).toBe('/api/internal/pusher-ack');
        expect(body).toMatchObject({
            correlation_id: 'corr-123',
            branch_id: 7,
            channel: 'private-branch.7',
            event_name: 'order.created',
            domain_event_id: 99,
        });
        // received_at must be a parseable ISO string (not undefined).
        expect(typeof body.received_at).toBe('string');
        expect(Number.isNaN(Date.parse(body.received_at))).toBe(false);
        // Timeout is enforced so a slow server cannot stall the UI.
        expect(options.timeout).toBe(3000);
    });

    it('is a no-op when correlation_id is missing', () => {
        sendAck({ version: 1, type: 'order.created', payload: {} }, 'private-branch.1');
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('is a no-op when raw is null/undefined', () => {
        sendAck(null, 'private-branch.1');
        sendAck(undefined, 'private-branch.1');
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('is a no-op when window.axios is missing', () => {
        delete window.axios;
        // Must not throw even without axios.
        expect(() => sendAck({ correlation_id: 'x', type: 't', payload: {}, version: 1 }, 'c'))
            .not.toThrow();
    });

    it('swallows axios.post throws without surfacing them', () => {
        // Mock to throw synchronously — the wrapper must contain it.
        window.axios = { post: () => { throw new Error('boom'); } };
        expect(() => sendAck({ correlation_id: 'corr-throws', type: 'x', payload: {}, version: 1 }, 'c'))
            .not.toThrow();
    });

    it('swallows axios.post rejected promise without surfacing it', () => {
        window.axios = {
            post: () => Promise.reject(new Error('http 500')),
        };
        expect(() => sendAck({ correlation_id: 'corr-rej', type: 'x', payload: {}, version: 1 }, 'c'))
            .not.toThrow();
    });
});

describe('eventContract — onEvents wires sendAck after handler', () => {
    let postSpy;
    let originalAxios;
    let originalEcho;

    beforeEach(() => {
        originalAxios = window.axios;
        postSpy = vi.fn().mockReturnValue({ catch: () => {} });
        window.axios = { post: postSpy };

        originalEcho = window.Echo;

        // Capture the rawHandler that onEvents binds so the test can
        // simulate Pusher delivering an envelope.
        let captured = null;
        const stopListening = vi.fn();
        const leave = vi.fn();
        const listen = vi.fn((_eventName, fn) => { captured = fn; });

        window.Echo = {
            private: () => ({ listen, stopListening }),
            leave,
            __getCaptured: () => captured,
        };
    });

    afterEach(() => {
        if (originalAxios !== undefined) {
            window.axios = originalAxios;
        } else {
            delete window.axios;
        }
        if (originalEcho !== undefined) {
            window.Echo = originalEcho;
        } else {
            delete window.Echo;
        }
    });

    it('calls user handler then POSTs an ack on a valid envelope', () => {
        const userHandler = vi.fn();
        onEvents(7, [{ broadcastAs: 'OrderCreated', handler: userHandler }]);

        const rawHandler = window.Echo.__getCaptured();
        expect(typeof rawHandler).toBe('function');

        rawHandler({
            version: 1,
            type: 'order.created',
            correlation_id: 'wire-1',
            branch_id: 7,
            payload: { id: 1 },
        });

        // Order matters: handler runs before the ack POST.
        expect(userHandler).toHaveBeenCalledTimes(1);
        expect(postSpy).toHaveBeenCalledTimes(1);
        const [, body] = postSpy.mock.calls[0];
        expect(body.correlation_id).toBe('wire-1');
        expect(body.channel).toBe('branch.7');
    });

    it('does NOT POST an ack when the envelope is invalid (parse threw)', () => {
        const userHandler = vi.fn();
        onEvents(7, [{ broadcastAs: 'OrderCreated', handler: userHandler }]);

        const rawHandler = window.Echo.__getCaptured();
        rawHandler({ /* missing version/type/payload */ });

        // parseEvent throws → handler never runs → ack must not fire.
        expect(userHandler).not.toHaveBeenCalled();
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('does NOT POST an ack when correlation_id is absent on a valid envelope', () => {
        const userHandler = vi.fn();
        onEvents(7, [{ broadcastAs: 'OrderCreated', handler: userHandler }]);

        const rawHandler = window.Echo.__getCaptured();
        rawHandler({
            version: 1,
            type: 'order.created',
            // no correlation_id
            payload: { id: 1 },
        });

        // Handler still runs (envelope is otherwise valid), but ack is
        // skipped because the server cannot dedupe a row without the id.
        expect(userHandler).toHaveBeenCalledTimes(1);
        expect(postSpy).not.toHaveBeenCalled();
    });
});
