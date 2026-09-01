"""Provider-agnostic contracts every STTProvider must satisfy.

These tests run the SAME assertions against both DeepgramProvider and
AssemblyAIProvider (parameterized via _PROVIDER_CASES) so swapping
VOICE_GATEWAY_STT_PROVIDER can never silently change the shape of what
reaches FoodKing.
"""
from __future__ import annotations

import json
import sys
import unittest
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import aiohttp

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from main import FoodKingTranscriptSink, Settings  # noqa: E402
from stt_providers import AssemblyAIProvider, DeepgramProvider  # noqa: E402


@dataclass
class _FakeMessage:
    type: aiohttp.WSMsgType
    data: str


class _FakeWebSocket:
    """Async-iterable stand-in for aiohttp.ClientWebSocketResponse."""

    def __init__(self, incoming: list[dict[str, Any]]) -> None:
        self._incoming = [
            _FakeMessage(aiohttp.WSMsgType.TEXT, json.dumps(payload)) for payload in incoming
        ]
        self.closed = False
        self.sent_json: list[dict[str, Any]] = []

    def __aiter__(self) -> "_FakeWebSocket":
        return self

    async def __anext__(self) -> _FakeMessage:
        if not self._incoming:
            raise StopAsyncIteration
        return self._incoming.pop(0)

    async def send_json(self, payload: dict[str, Any]) -> None:
        self.sent_json.append(payload)

    async def close(self) -> None:
        self.closed = True


class _FakeSink:
    def __init__(self) -> None:
        self.events: list[dict[str, Any]] = []

    async def emit(self, *, final: bool, turn_id: str, text: str, confidence) -> None:
        self.events.append({"final": final, "turn_id": turn_id, "text": text, "confidence": confidence})


def _deepgram_partial(text: str, turn_index: int) -> dict[str, Any]:
    return {"type": "TurnInfo", "event": "Update", "transcript": text, "turn_index": turn_index, "confidence": 0.8}


def _deepgram_final(text: str, turn_index: int) -> dict[str, Any]:
    return {"type": "TurnInfo", "event": "EndOfTurn", "transcript": text, "turn_index": turn_index, "confidence": 0.95}


def _assemblyai_partial(text: str, order: int) -> dict[str, Any]:
    return {"type": "Turn", "end_of_turn": False, "transcript": text, "turn_order": order, "speaker_label": "A"}


def _assemblyai_final(text: str, order: int) -> dict[str, Any]:
    return {
        "type": "Turn", "end_of_turn": True, "transcript": text, "turn_order": order,
        "speaker_label": "A", "end_of_turn_confidence": 0.91,
    }


@dataclass
class _ProviderCase:
    name: str
    make_provider: Any
    partial: Any
    final: Any


def _make_deepgram(sink: _FakeSink) -> DeepgramProvider:
    return DeepgramProvider(None, "key", sink)  # type: ignore[arg-type]


def _make_assemblyai(sink: _FakeSink) -> AssemblyAIProvider:
    return AssemblyAIProvider(None, "key", sink)  # type: ignore[arg-type]


_PROVIDER_CASES = [
    _ProviderCase("deepgram", _make_deepgram, _deepgram_partial, _deepgram_final),
    _ProviderCase("assemblyai", _make_assemblyai, _assemblyai_partial, _assemblyai_final),
]


class PartialTranscriptContractTest(unittest.IsolatedAsyncioTestCase):
    async def test_partial_turn_is_forwarded_as_non_final(self) -> None:
        for case in _PROVIDER_CASES:
            with self.subTest(provider=case.name):
                sink = _FakeSink()
                provider = case.make_provider(sink)
                provider.ws = _FakeWebSocket([case.partial("un cayenne", 1)])
                await provider._receive_events()
                self.assertEqual(1, len(sink.events))
                self.assertFalse(sink.events[0]["final"])
                self.assertEqual("un cayenne", sink.events[0]["text"])


class FinalTranscriptContractTest(unittest.IsolatedAsyncioTestCase):
    async def test_final_turn_is_forwarded_exactly_once(self) -> None:
        for case in _PROVIDER_CASES:
            with self.subTest(provider=case.name):
                sink = _FakeSink()
                provider = case.make_provider(sink)
                # Same final turn delivered twice (provider-side retransmit) must dedup.
                provider.ws = _FakeWebSocket([case.final("un cayenne salade tomate", 1), case.final("un cayenne salade tomate", 1)])
                await provider._receive_events()
                finals = [e for e in sink.events if e["final"]]
                self.assertEqual(1, len(finals))
                self.assertEqual("un cayenne salade tomate", finals[0]["text"])


class SpeakerAttributionContractTest(unittest.IsolatedAsyncioTestCase):
    async def test_speaker_role_is_never_guessed_from_diarization(self) -> None:
        # Deliberate product decision, not an oversight: External Media captures
        # ONE mixed bridge stream and transcription only attaches post-consent
        # (both legs usually already bridged), so there is no reliable "who
        # spoke first" baseline. The backend only accepts speaker in
        # {caller, employee, unknown} — an unverified guess is worse than
        # "unknown". This test locks that decision at the provider boundary:
        # even when AssemblyAI attaches a speaker_label, the sink never sees it.
        for case in _PROVIDER_CASES:
            with self.subTest(provider=case.name):
                sink = _FakeSink()
                provider = case.make_provider(sink)
                provider.ws = _FakeWebSocket([case.final("bonjour", 1)])
                await provider._receive_events()
                self.assertNotIn("speaker", sink.events[0])

    async def test_foodking_wire_payload_always_says_unknown(self) -> None:
        # Even though AssemblyAI attaches a real speaker_label to the raw Turn
        # payload, the actual HTTP body FoodKing receives must always read
        # "unknown" — this is the wire-level lock, one level below the sink.
        class _RecordingFoodKing:
            def __init__(self) -> None:
                self.posted: list[dict[str, Any]] = []

            async def event(self, payload: dict[str, Any]) -> None:
                self.posted.append(payload)

        foodking = _RecordingFoodKing()
        wire_sink = FoodKingTranscriptSink(foodking, "call-speaker-001")  # type: ignore[arg-type]
        provider = _make_assemblyai(wire_sink)
        provider.ws = _FakeWebSocket([_assemblyai_final("bonjour", 1)])
        await provider._receive_events()
        self.assertEqual(1, len(foodking.posted))
        self.assertEqual("unknown", foodking.posted[0]["speaker"])


class CorrectionContractTest(unittest.IsolatedAsyncioTestCase):
    async def test_a_correcting_final_turn_is_a_distinct_event(self) -> None:
        # "Non, sans oignon" after "salade tomate oignon" must reach FoodKing
        # as its OWN turn, never merged with or replacing the prior one — the
        # deterministic extractor (not the STT layer) owns correction logic.
        for case in _PROVIDER_CASES:
            with self.subTest(provider=case.name):
                sink = _FakeSink()
                provider = case.make_provider(sink)
                provider.ws = _FakeWebSocket([
                    case.final("un cayenne salade tomate oignon", 1),
                    case.final("non sans oignon", 2),
                ])
                await provider._receive_events()
                finals = [e for e in sink.events if e["final"]]
                self.assertEqual(2, len(finals))
                self.assertNotEqual(finals[0]["turn_id"], finals[1]["turn_id"])
                self.assertEqual("non sans oignon", finals[1]["text"])


class NormalCloseContractTest(unittest.IsolatedAsyncioTestCase):
    async def test_deepgram_sends_force_end_turn_then_close_stream(self) -> None:
        sink = _FakeSink()
        provider = _make_deepgram(sink)
        provider.ws = _FakeWebSocket([])
        await provider.close()
        self.assertEqual([{"type": "ForceEndTurn"}, {"type": "CloseStream"}], provider.ws.sent_json)
        self.assertTrue(provider.ws.closed)

    async def test_assemblyai_sends_terminate(self) -> None:
        sink = _FakeSink()
        provider = _make_assemblyai(sink)
        provider.ws = _FakeWebSocket([])
        await provider.close()
        self.assertEqual([{"type": "Terminate"}], provider.ws.sent_json)
        self.assertTrue(provider.ws.closed)


class ProviderDisconnectContractTest(unittest.IsolatedAsyncioTestCase):
    async def test_receive_loop_ends_cleanly_when_socket_closes_abruptly(self) -> None:
        # An abrupt close is just an empty message stream (no exception) for
        # this fake, mirroring aiohttp's async-iterator ending when the
        # connection drops — the caller (AriGateway._wait_for_consent) is
        # responsible for the retry loop, not the provider.
        for case in _PROVIDER_CASES:
            with self.subTest(provider=case.name):
                sink = _FakeSink()
                provider = case.make_provider(sink)
                provider.ws = _FakeWebSocket([])
                await provider._receive_events()  # must return, not raise
                self.assertEqual([], sink.events)


class MissingKeyOnlyForSelectedProviderTest(unittest.TestCase):
    _BASE_ENV = {
        "VOICE_GATEWAY_FOODKING_BASE_URL": "https://example.invalid",
        "VOICE_ORDER_GATEWAY_SECRET": "x" * 32,
        "VOICE_GATEWAY_ARI_PASSWORD": "ari-pw",
    }

    def _settings(self, monkeypatch_env: dict[str, str]) -> Settings:
        import os
        old = dict(os.environ)
        os.environ.update(self._BASE_ENV)
        os.environ.update(monkeypatch_env)
        try:
            return Settings.from_env()
        finally:
            os.environ.clear()
            os.environ.update(old)

    def test_assemblyai_selected_requires_only_assemblyai_key(self) -> None:
        with self.assertRaises(RuntimeError):
            self._settings({"VOICE_GATEWAY_STT_PROVIDER": "assemblyai"})
        settings = self._settings({
            "VOICE_GATEWAY_STT_PROVIDER": "assemblyai",
            "VOICE_GATEWAY_ASSEMBLYAI_API_KEY": "aai-key",
        })
        self.assertEqual("assemblyai", settings.stt_provider)
        self.assertEqual("", settings.deepgram_api_key)

    def test_deepgram_selected_requires_only_deepgram_key(self) -> None:
        with self.assertRaises(RuntimeError):
            self._settings({"VOICE_GATEWAY_STT_PROVIDER": "deepgram"})
        settings = self._settings({
            "VOICE_GATEWAY_STT_PROVIDER": "deepgram",
            "VOICE_GATEWAY_DEEPGRAM_API_KEY": "dg-key",
        })
        self.assertEqual("deepgram", settings.stt_provider)
        self.assertEqual("", settings.assemblyai_api_key)

    def test_unknown_provider_name_is_rejected(self) -> None:
        with self.assertRaises(RuntimeError):
            self._settings({
                "VOICE_GATEWAY_STT_PROVIDER": "whisper",
                "VOICE_GATEWAY_ASSEMBLYAI_API_KEY": "aai-key",
            })


if __name__ == "__main__":
    unittest.main()
