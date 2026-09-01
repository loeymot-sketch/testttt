from __future__ import annotations

import asyncio
import base64
import json
import os
import signal
import uuid
from dataclasses import dataclass, field
from typing import Any
from urllib.parse import quote

import aiohttp

from rtp import AudioBatcher, rtp_payload
from signing import canonical_json, signed_headers
from stt_providers import STTProvider, build_stt_provider

DEFAULT_ASSEMBLYAI_KEYTERMS = [
    "Cayenne", "cheeseburger", "cordon bleu", "tenders",
    "salade", "tomate", "oignon", "cheddar", "fromagère",
    "algérienne", "andalouse", "samouraï", "sauce blanche",
    "supplément", "tacos",
]


@dataclass(frozen=True)
class Settings:
    foodking_base_url: str
    gateway_id: str
    gateway_secret: str
    stt_provider: str
    deepgram_api_key: str
    assemblyai_api_key: str
    assemblyai_model: str
    assemblyai_keyterms: list[str]
    ari_url: str
    ari_username: str
    ari_password: str
    employee_endpoint: str
    rtp_advertise_host: str
    rtp_bind_host: str = "0.0.0.0"

    @classmethod
    def from_env(cls) -> "Settings":
        stt_provider = os.environ.get("VOICE_GATEWAY_STT_PROVIDER", "assemblyai").strip().lower()
        keyterms_env = os.environ.get("VOICE_GATEWAY_ASSEMBLYAI_KEYTERMS", "")
        keyterms = [t.strip() for t in keyterms_env.split(",") if t.strip()] or list(DEFAULT_ASSEMBLYAI_KEYTERMS)
        settings = cls(
            foodking_base_url=os.environ.get("VOICE_GATEWAY_FOODKING_BASE_URL", "").rstrip("/"),
            gateway_id=os.environ.get("VOICE_ORDER_GATEWAY_ID", "restaurant-main"),
            gateway_secret=os.environ.get("VOICE_ORDER_GATEWAY_SECRET", ""),
            stt_provider=stt_provider,
            deepgram_api_key=os.environ.get("VOICE_GATEWAY_DEEPGRAM_API_KEY", ""),
            assemblyai_api_key=os.environ.get("VOICE_GATEWAY_ASSEMBLYAI_API_KEY", ""),
            assemblyai_model=os.environ.get("VOICE_GATEWAY_ASSEMBLYAI_MODEL", "universal-streaming-multilingual"),
            assemblyai_keyterms=keyterms,
            ari_url=os.environ.get("VOICE_GATEWAY_ARI_URL", "http://127.0.0.1:8088/ari").rstrip("/"),
            ari_username=os.environ.get("VOICE_GATEWAY_ARI_USERNAME", "foodking"),
            ari_password=os.environ.get("VOICE_GATEWAY_ARI_PASSWORD", ""),
            employee_endpoint=os.environ.get("VOICE_GATEWAY_EMPLOYEE_ENDPOINT", "PJSIP/100"),
            rtp_advertise_host=os.environ.get("VOICE_GATEWAY_RTP_ADVERTISE_HOST", "127.0.0.1"),
            rtp_bind_host=os.environ.get("VOICE_GATEWAY_RTP_BIND_HOST", "0.0.0.0"),
        )
        if settings.stt_provider not in {"assemblyai", "deepgram"}:
            raise RuntimeError(f"VOICE_GATEWAY_STT_PROVIDER invalide: {settings.stt_provider!r} (assemblyai|deepgram attendu).")
        # Only the SELECTED provider's key is required — switching provider
        # must never require carrying an unused vendor's secret.
        stt_key_var, stt_key_value = (
            ("VOICE_GATEWAY_ASSEMBLYAI_API_KEY", settings.assemblyai_api_key)
            if settings.stt_provider == "assemblyai"
            else ("VOICE_GATEWAY_DEEPGRAM_API_KEY", settings.deepgram_api_key)
        )
        required = {
            "VOICE_GATEWAY_FOODKING_BASE_URL": settings.foodking_base_url,
            "VOICE_ORDER_GATEWAY_SECRET": settings.gateway_secret,
            stt_key_var: stt_key_value,
            "VOICE_GATEWAY_ARI_PASSWORD": settings.ari_password,
        }
        missing = [name for name, value in required.items() if not value]
        if missing or len(settings.gateway_secret) < 24:
            raise RuntimeError("Configuration passerelle incomplète; renseigner les secrets hors dépôt.")
        return settings


class FoodKingClient:
    def __init__(self, session: aiohttp.ClientSession, settings: Settings) -> None:
        self.session = session
        self.settings = settings

    async def post(self, path: str, payload: dict[str, Any]) -> dict[str, Any]:
        body = canonical_json(payload)
        headers = signed_headers(self.settings.gateway_id, self.settings.gateway_secret, body)
        async with self.session.post(self.settings.foodking_base_url + path, data=body, headers=headers, timeout=8) as response:
            data = await response.json(content_type=None)
            if response.status >= 400:
                raise RuntimeError(f"FoodKing gateway request rejected ({response.status})")
            return data.get("data", data)

    async def event(self, payload: dict[str, Any]) -> None:
        await self.post("/api/voice-order/gateway/events", payload)

    async def media_authorized(self, call_id: str) -> bool:
        data = await self.post("/api/voice-order/gateway/authorize-media", {"call_id": call_id})
        return data.get("media_authorized") is True


class RtpReceiver(asyncio.DatagramProtocol):
    def __init__(self, queue: asyncio.Queue[bytes]) -> None:
        self.queue = queue
        self.batcher = AudioBatcher()

    def datagram_received(self, data: bytes, addr: Any) -> None:
        del addr
        for batch in self.batcher.feed(rtp_payload(data)):
            try:
                self.queue.put_nowait(batch)
            except asyncio.QueueFull:
                # Drop oldest audio rather than increasing conversational latency.
                try:
                    self.queue.get_nowait()
                    self.queue.put_nowait(batch)
                except (asyncio.QueueEmpty, asyncio.QueueFull):
                    pass


class FoodKingTranscriptSink:
    """Adapts the provider-agnostic (final, turn_id, text, confidence) event
    into the exact FoodKing gateway wire event, unchanged regardless of which
    STTProvider produced it."""

    def __init__(self, foodking: FoodKingClient, call_id: str) -> None:
        self.foodking = foodking
        self.call_id = call_id

    async def emit(self, *, final: bool, turn_id: str, text: str, confidence: float | None) -> None:
        await self.foodking.event({
            "event": "transcript.final" if final else "transcript.update",
            "call_id": self.call_id,
            "turn_id": turn_id,
            # Never a guessed diarization label — see AssemblyAIProvider for why.
            "speaker": "unknown",
            "text": text,
            "confidence": confidence,
        })


@dataclass
class CallSession:
    call_id: str
    inbound_channel_id: str
    bridge_id: str
    caller_number: str | None
    caller_name: str | None = None
    employee_channel_id: str | None = None
    external_channel_id: str | None = None
    stt: STTProvider | None = None
    rtp_transport: asyncio.DatagramTransport | None = None
    consent_task: asyncio.Task[Any] | None = None
    ended: bool = False


class AriGateway:
    APP = "foodking-voice"

    def __init__(self, session: aiohttp.ClientSession, settings: Settings) -> None:
        self.session = session
        self.settings = settings
        self.foodking = FoodKingClient(session, settings)
        self.auth = aiohttp.BasicAuth(settings.ari_username, settings.ari_password)
        self.calls: dict[str, CallSession] = {}
        self.channel_to_call: dict[str, str] = {}

    async def run(self) -> None:
        credentials = base64.b64encode(f"{self.settings.ari_username}:{self.settings.ari_password}".encode()).decode()
        ws_url = self.settings.ari_url.replace("http://", "ws://").replace("https://", "wss://")
        async with self.session.ws_connect(
            f"{ws_url}/events?app={quote(self.APP)}",
            headers={"Authorization": f"Basic {credentials}"},
            heartbeat=20,
        ) as ws:
            async for message in ws:
                if message.type != aiohttp.WSMsgType.TEXT:
                    continue
                event = json.loads(message.data)
                if event.get("type") == "StasisStart":
                    await self._stasis_start(event)
                elif event.get("type") in {"StasisEnd", "ChannelDestroyed"}:
                    await self._channel_ended(event)

    async def _stasis_start(self, event: dict[str, Any]) -> None:
        channel = event.get("channel") or {}
        channel_id = str(channel.get("id") or "")
        args = event.get("args") or []
        if not channel_id:
            return
        if args and args[0] in {"employee", "external"}:
            if len(args) < 2 or args[1] not in self.calls:
                return
            call = self.calls[args[1]]
            self.channel_to_call[channel_id] = call.call_id
            if args[0] == "employee":
                call.employee_channel_id = channel_id
            else:
                call.external_channel_id = channel_id
            await self._ari("POST", f"/bridges/{call.bridge_id}/addChannel", params={"channel": channel_id})
            return

        call_id = "fk-" + uuid.uuid4().hex
        bridge = await self._ari("POST", "/bridges", params={"type": "mixing", "name": call_id})
        call = CallSession(
            call_id=call_id,
            inbound_channel_id=channel_id,
            bridge_id=str(bridge["id"]),
            caller_number=(channel.get("caller") or {}).get("number"),
            caller_name=(channel.get("caller") or {}).get("name"),
        )
        self.calls[call_id] = call
        self.channel_to_call[channel_id] = call_id
        await self._ari("POST", f"/channels/{channel_id}/answer")
        await self._ari("POST", f"/bridges/{call.bridge_id}/addChannel", params={"channel": channel_id})
        await self._ari("POST", "/channels", params={
            "endpoint": self.settings.employee_endpoint,
            "app": self.APP,
            "appArgs": f"employee,{call_id}",
            "callerId": call.caller_number or "Appel restaurant",
        })
        await self.foodking.event({
            "event": "call.started",
            "call_id": call_id,
            "caller_number": call.caller_number,
            "caller_name": call.caller_name,
        })
        call.consent_task = asyncio.create_task(self._wait_for_consent(call))

    async def _wait_for_consent(self, call: CallSession) -> None:
        while not call.ended:
            try:
                if await self.foodking.media_authorized(call.call_id):
                    try:
                        await self._attach_transcription(call)
                        return
                    except Exception:
                        # L'appel humain continue. Une panne STT est retentée sans
                        # créer de commande ni conserver de paquets audio.
                        await asyncio.sleep(2)
                        continue
            except Exception:
                pass
            await asyncio.sleep(0.75)

    async def _attach_transcription(self, call: CallSession) -> None:
        # No pre-consent packets exist: the UDP listener and STT socket are created here.
        sink = FoodKingTranscriptSink(self.foodking, call.call_id)
        stt = build_stt_provider(
            self.settings.stt_provider,
            self.session,
            sink,
            deepgram_key=self.settings.deepgram_api_key,
            assemblyai_key=self.settings.assemblyai_api_key,
            assemblyai_model=self.settings.assemblyai_model,
            keyterms=self.settings.assemblyai_keyterms,
        )
        transport, _ = await asyncio.get_running_loop().create_datagram_endpoint(
            lambda: RtpReceiver(stt.queue),
            local_addr=(self.settings.rtp_bind_host, 0),
        )
        try:
            call.rtp_transport = transport
            port = transport.get_extra_info("sockname")[1]
            await stt.start()
            call.stt = stt
            external = await self._ari("POST", "/channels/externalMedia", params={
                "app": self.APP,
                "appArgs": f"external,{call.call_id}",
                "external_host": f"{self.settings.rtp_advertise_host}:{port}",
                "format": "ulaw",
                "transport": "udp",
                "encapsulation": "rtp",
                "connection_type": "client",
                "direction": "both",
            })
            call.external_channel_id = str(external.get("id") or "")
            if call.external_channel_id:
                self.channel_to_call[call.external_channel_id] = call.call_id
        except Exception:
            await stt.close()
            transport.close()
            call.stt = None
            call.rtp_transport = None
            raise

    async def _channel_ended(self, event: dict[str, Any]) -> None:
        channel = event.get("channel") or {}
        call_id = self.channel_to_call.get(str(channel.get("id") or ""))
        call = self.calls.get(call_id or "")
        if not call or call.ended or str(channel.get("id")) != call.inbound_channel_id:
            return
        call.ended = True
        if call.consent_task:
            call.consent_task.cancel()
        if call.stt:
            await call.stt.close()
        if call.rtp_transport:
            call.rtp_transport.close()
        try:
            await self.foodking.event({"event": "call.ended", "call_id": call.call_id, "reason": "hangup"})
        finally:
            self.calls.pop(call.call_id, None)
            for channel_id, mapped in list(self.channel_to_call.items()):
                if mapped == call.call_id:
                    self.channel_to_call.pop(channel_id, None)

    async def _ari(self, method: str, path: str, *, params: dict[str, Any] | None = None) -> dict[str, Any]:
        async with self.session.request(method, self.settings.ari_url + path, params=params, auth=self.auth, timeout=8) as response:
            if response.status >= 400:
                raise RuntimeError(f"ARI request rejected ({response.status})")
            if response.content_length == 0:
                return {}
            return await response.json(content_type=None)


async def async_main() -> None:
    settings = Settings.from_env()
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        loop.add_signal_handler(sig, stop.set)
    async with aiohttp.ClientSession() as session:
        gateway = AriGateway(session, settings)
        runner = asyncio.create_task(gateway.run())
        waiter = asyncio.create_task(stop.wait())
        done, pending = await asyncio.wait({runner, waiter}, return_when=asyncio.FIRST_COMPLETED)
        for task in pending:
            task.cancel()
        await asyncio.gather(*done, *pending, return_exceptions=True)


if __name__ == "__main__":
    asyncio.run(async_main())
