import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const panel = fs.readFileSync(path.join(root, 'resources/js/components/admin/pos/VoiceOrderAssistantPanel.vue'), 'utf8');
const pos = fs.readFileSync(path.join(root, 'resources/js/components/admin/pos/PosComponent.vue'), 'utf8');
const routes = fs.readFileSync(path.join(root, 'resources/js/router/modules/posRoutes.js'), 'utf8');
const posApp = fs.readFileSync(path.join(root, 'resources/js/pos-app.js'), 'utf8');

describe('voice order assistant V1 contract', () => {
    it('requires explicit caller notice before transcription', () => {
        expect(panel).toContain('Client informé — démarrer');
        expect(panel).toContain('caller_informed: true');
        expect(panel).toContain("selectedCall.status !== 'ended' && !selectedCall.consented_at");
        expect(panel).not.toContain('speechSynthesis');
    });

    it('never submits an order from the assistant panel', () => {
        expect(panel).not.toContain("posOrder/save");
        expect(panel).not.toContain('phoneOrderSubmit');
        expect(panel).toContain("$emit('review-item', line)");
    });

    it('links only after the existing phone order returned a concrete order id', () => {
        const savePosition = pos.indexOf("const orderResponse = await this.$store.dispatch('posOrder/save', payload)");
        const linkPosition = pos.indexOf('persistPendingVoiceOrderLink(this.voiceOrderSelectedCallId, createdOrderId)');
        expect(savePosition).toBeGreaterThan(0);
        expect(linkPosition).toBeGreaterThan(savePosition);
        expect(pos).toContain('voice_order_pending_link:v1:b${branch}:u${user}');
        expect(pos).toContain('24 * 60 * 60 * 1000');
        expect(pos).toContain("this.voiceOrderSelectedCallId = call?.order_id ? null");
    });

    it('exposes the dedicated assistant route in both POS entries', () => {
        expect(routes).toContain('/admin/pos/voice-assistant');
        expect(routes).toContain('voiceAssistant: true');
        expect(posApp).toContain('/admin/pos-v4/voice-assistant');
        expect(posApp).toContain('voiceAssistant: true');
    });
});
