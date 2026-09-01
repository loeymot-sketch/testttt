from __future__ import annotations

import asyncio
import hashlib
import hmac
import struct
import unittest

from main import CallSession
from rtp import AudioBatcher, rtp_payload
from signing import canonical_json, signed_headers
from stt_providers import DeepgramProvider


class RtpContractTest(unittest.TestCase):
    def test_payload_supports_csrc_extension_and_padding(self) -> None:
        first = 0x80 | 0x20 | 0x10 | 0x01  # V2, padding, extension, one CSRC
        header = bytes([first, 0x00]) + struct.pack("!HII", 1, 160, 99)
        csrc = struct.pack("!I", 123)
        extension = struct.pack("!HH", 0xBEDE, 1) + b"abcd"
        packet = header + csrc + extension + b"audio" + b"\x00\x02"
        self.assertEqual(b"audio", rtp_payload(packet))

    def test_audio_is_batched_to_eighty_milliseconds(self) -> None:
        batcher = AudioBatcher(target_bytes=640)
        self.assertEqual([], batcher.feed(b"a" * 160))
        self.assertEqual([], batcher.feed(b"b" * 160))
        self.assertEqual([], batcher.feed(b"c" * 160))
        batches = batcher.feed(b"d" * 160)
        self.assertEqual(1, len(batches))
        self.assertEqual(640, len(batches[0]))


class SignatureContractTest(unittest.TestCase):
    def test_signature_matches_backend_canonical_formula(self) -> None:
        body = canonical_json({"event": "call.started", "call_id": "call-signing-001"})
        headers = signed_headers("gw-a", "secret-abcdefghijklmnopqrstuvwxyz", body, timestamp=1700000000, event_id="event-signing-001")
        expected = hmac.new(
            b"secret-abcdefghijklmnopqrstuvwxyz",
            b"1700000000\nevent-signing-001\n" + body,
            hashlib.sha256,
        ).hexdigest()
        self.assertEqual(expected, headers["X-Voice-Signature"])


class ConsentContractTest(unittest.IsolatedAsyncioTestCase):
    async def test_transcription_attach_happens_only_after_authorization(self) -> None:
        class FakeFoodKing:
            def __init__(self) -> None:
                self.answers = [False, False, True]
                self.calls = 0

            async def media_authorized(self, call_id: str) -> bool:
                self.calls += 1
                return self.answers.pop(0)

        fake = FakeFoodKing()
        attached: list[str] = []

        class Harness:
            foodking = fake

            async def _attach_transcription(self, call: CallSession) -> None:
                attached.append(call.call_id)

            async def _wait_for_consent(self, call: CallSession) -> None:
                while not call.ended:
                    if await self.foodking.media_authorized(call.call_id):
                        await self._attach_transcription(call)
                        return
                    await asyncio.sleep(0)

        call = CallSession("call-consent-001", "inbound", "bridge", None)
        await Harness()._wait_for_consent(call)
        self.assertEqual(3, fake.calls)
        self.assertEqual(["call-consent-001"], attached)


class DeepgramShutdownContractTest(unittest.IsolatedAsyncioTestCase):
    async def test_force_end_turn_precedes_close_stream(self) -> None:
        class FakeWebSocket:
            def __init__(self) -> None:
                self.closed = False
                self.messages: list[dict[str, str]] = []

            async def send_json(self, payload: dict[str, str]) -> None:
                self.messages.append(payload)

            async def close(self) -> None:
                self.closed = True

        stream = DeepgramProvider(None, "key", None)  # type: ignore[arg-type]
        websocket = FakeWebSocket()
        stream.ws = websocket  # type: ignore[assignment]

        await stream.close()

        self.assertEqual(
            [{"type": "ForceEndTurn"}, {"type": "CloseStream"}],
            websocket.messages,
        )
        self.assertTrue(websocket.closed)


if __name__ == "__main__":
    unittest.main()
