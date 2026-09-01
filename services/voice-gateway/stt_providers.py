"""Speech-to-text provider abstraction.

The gateway's ARI/RTP/FoodKing-signing plumbing must not know or care which
STT vendor is behind the call: every provider batches PCMU/8kHz audio in and
emits normalized (final: bool, turn_id: str, text: str, confidence) events
out, via a TranscriptSink. Swapping VOICE_GATEWAY_STT_PROVIDER must never
require touching main.py's call-handling logic.
"""
from __future__ import annotations

import asyncio
import json
import uuid
from abc import ABC, abstractmethod
from typing import Any, Protocol
from urllib.parse import quote

import aiohttp


class TranscriptSink(Protocol):
    """Receives normalized transcript turns, independent of the STT provider."""

    async def emit(self, *, final: bool, turn_id: str, text: str, confidence: float | None) -> None:
        ...


class STTProvider(ABC):
    """Common contract every speech-to-text adapter must satisfy."""

    def __init__(self) -> None:
        self.queue: asyncio.Queue[bytes] = asyncio.Queue(maxsize=40)

    @abstractmethod
    async def start(self) -> None:
        """Open the provider connection. Called only after consent is granted."""

    @abstractmethod
    async def close(self) -> None:
        """Flush/close the provider session. Must be safe to call more than once."""


class _WebSocketTurnProvider(STTProvider):
    """Shared send/receive loop for the two WebSocket-based providers below."""

    def __init__(self, session: aiohttp.ClientSession, sink: TranscriptSink) -> None:
        super().__init__()
        self.session = session
        self.sink = sink
        self.ws: aiohttp.ClientWebSocketResponse | None = None
        self.tasks: list[asyncio.Task[Any]] = []
        self.last_final_turn: str | None = None

    async def _connect(self, url: str, headers: dict[str, str]) -> None:
        self.ws = await self.session.ws_connect(url, headers=headers, heartbeat=15, timeout=8)
        self.tasks = [
            asyncio.create_task(self._send_audio()),
            asyncio.create_task(self._receive_events()),
        ]

    async def _send_audio(self) -> None:
        assert self.ws is not None
        while True:
            await self.ws.send_bytes(await self.queue.get())

    async def _receive_events(self) -> None:
        raise NotImplementedError

    async def _graceful_shutdown_messages(self) -> list[dict[str, str]]:
        """Messages to send, in order, before closing. Provider-specific."""
        raise NotImplementedError

    async def close(self) -> None:
        sender = self.tasks[0] if self.tasks else None
        receiver = self.tasks[1] if len(self.tasks) > 1 else None
        if sender:
            sender.cancel()
            await asyncio.gather(sender, return_exceptions=True)

        if self.ws and not self.ws.closed:
            try:
                for message in await self._graceful_shutdown_messages():
                    await self.ws.send_json(message)
                if receiver:
                    await asyncio.wait_for(receiver, timeout=2.0)
            except (asyncio.TimeoutError, asyncio.CancelledError, Exception):
                if receiver and not receiver.done():
                    receiver.cancel()
            finally:
                if not self.ws.closed:
                    await self.ws.close()

        for task in self.tasks:
            if not task.done():
                task.cancel()
        await asyncio.gather(*self.tasks, return_exceptions=True)

    def _emit_once_per_final(self, *, final: bool, turn_id: str) -> bool:
        """Returns False if this final turn_id was already emitted (dedup)."""
        if final and self.last_final_turn == turn_id:
            return False
        if final:
            self.last_final_turn = turn_id
        return True


class DeepgramProvider(_WebSocketTurnProvider):
    URL = (
        "wss://api.eu.deepgram.com/v2/listen"
        "?model=flux-general-multi&language_hint=fr&encoding=mulaw&sample_rate=8000"
    )

    def __init__(self, session: aiohttp.ClientSession, key: str, sink: TranscriptSink) -> None:
        super().__init__(session, sink)
        self.key = key

    async def start(self) -> None:
        await self._connect(self.URL, {"Authorization": f"Token {self.key}"})

    async def _graceful_shutdown_messages(self) -> list[dict[str, str]]:
        # Flux processes messages in order: end the current turn, flush
        # remaining audio, receive EndOfTurn, then close cleanly.
        return [{"type": "ForceEndTurn"}, {"type": "CloseStream"}]

    async def _receive_events(self) -> None:
        assert self.ws is not None
        async for message in self.ws:
            if message.type != aiohttp.WSMsgType.TEXT:
                continue
            try:
                payload = json.loads(message.data)
            except json.JSONDecodeError:
                continue
            if payload.get("type") != "TurnInfo":
                continue
            text = str(payload.get("transcript") or payload.get("text") or "").strip()
            if not text:
                continue
            final = payload.get("event") == "EndOfTurn"
            turn_id = str(payload.get("turn_index") or payload.get("id") or uuid.uuid4().hex)
            if not self._emit_once_per_final(final=final, turn_id=turn_id):
                continue
            await self.sink.emit(final=final, turn_id=turn_id, text=text, confidence=payload.get("confidence"))


class AssemblyAIProvider(_WebSocketTurnProvider):
    """Universal-Streaming v3 adapter.

    Verified against AssemblyAI's own docs 2026-08-31 (not guessed):
    https://www.assemblyai.com/docs/streaming/api-spec/streaming-websocket
    https://www.assemblyai.com/docs/streaming
    """

    URL = "wss://streaming.assemblyai.com/v3/ws"

    def __init__(
        self,
        session: aiohttp.ClientSession,
        key: str,
        sink: TranscriptSink,
        *,
        model: str = "universal-streaming-multilingual",
        sample_rate: int = 8000,
        encoding: str = "pcm_mulaw",
        keyterms: list[str] | None = None,
    ) -> None:
        super().__init__(session, sink)
        self.key = key
        self.model = model
        self.sample_rate = sample_rate
        self.encoding = encoding
        self.keyterms = list(keyterms or [])
        # A fresh id per connection keeps turn_id globally unique across
        # reconnects. AssemblyAI's own turn_order restarts at 1 each new
        # session; the backend dedups transcript writes by turn_id
        # (VoiceOrderTranscriptStoreTest "duplicate final turn... do not
        # duplicate persistence"), so two sessions both emitting turn_id="1"
        # would silently drop a real segment on reconnect instead of
        # persisting it — exactly the "reprise sans dupliquer un segment"
        # requirement, taken literally: dedup only within a session, never
        # across one.
        self.session_id = uuid.uuid4().hex[:12]

    def _connect_url(self) -> str:
        params = {
            "sample_rate": str(self.sample_rate),
            "encoding": self.encoding,
            "format_turns": "true",
            "speaker_labels": "true",
            "max_speakers": "2",
            "speech_model": self.model,
        }
        query = "&".join(f"{key}={quote(str(value))}" for key, value in params.items())
        if self.keyterms:
            # keyterms_prompt: JSON array, max 100 terms per AssemblyAI's spec.
            terms_json = json.dumps(self.keyterms[:100], ensure_ascii=False)
            query += f"&keyterms_prompt={quote(terms_json)}"
        return f"{self.URL}?{query}"

    async def start(self) -> None:
        # No "Bearer"/"Token" prefix — the raw API key is the header value.
        await self._connect(self._connect_url(), {"Authorization": self.key})

    async def _graceful_shutdown_messages(self) -> list[dict[str, str]]:
        return [{"type": "Terminate"}]

    async def _receive_events(self) -> None:
        assert self.ws is not None
        async for message in self.ws:
            if message.type != aiohttp.WSMsgType.TEXT:
                continue
            try:
                payload = json.loads(message.data)
            except json.JSONDecodeError:
                continue
            if payload.get("type") != "Turn":
                continue
            text = str(payload.get("transcript") or "").strip()
            if not text:
                continue
            final = bool(payload.get("end_of_turn"))
            order = payload.get("turn_order")
            turn_id = f"{self.session_id}:{order if order is not None else uuid.uuid4().hex}"
            if not self._emit_once_per_final(final=final, turn_id=turn_id):
                continue
            # Diarization ("A"/"B" speaker_label) is requested (speaker_labels=
            # true, max_speakers=2) because it measurably improves turn
            # segmentation, but its label is NOT forwarded as a caller/employee
            # role: External Media captures ONE mixed bridge stream, and
            # transcription only attaches after consent — by which point both
            # legs are typically already bridged, so there is no reliable
            # "who spoke first" baseline to anchor a mapping on. The backend
            # only accepts speaker in {caller, employee, unknown}
            # (VoiceOrderGatewayController); an unverified guess would be
            # worse than "unknown". Correct fix is per-leg (non-mixed) audio
            # capture — a V2 Asterisk/ARI change, not a gateway one.
            await self.sink.emit(
                final=final,
                turn_id=turn_id,
                text=text,
                confidence=self._confidence(payload),
            )

    @staticmethod
    def _confidence(payload: dict[str, Any]) -> float | None:
        value = payload.get("end_of_turn_confidence")
        return float(value) if isinstance(value, (int, float)) else None


def build_stt_provider(
    provider_name: str,
    session: aiohttp.ClientSession,
    sink: TranscriptSink,
    *,
    deepgram_key: str,
    assemblyai_key: str,
    assemblyai_model: str,
    keyterms: list[str] | None = None,
) -> STTProvider:
    if provider_name == "assemblyai":
        return AssemblyAIProvider(session, assemblyai_key, sink, model=assemblyai_model, keyterms=keyterms)
    if provider_name == "deepgram":
        return DeepgramProvider(session, deepgram_key, sink)
    raise RuntimeError(f"Fournisseur STT inconnu: {provider_name!r} (assemblyai|deepgram attendu).")
