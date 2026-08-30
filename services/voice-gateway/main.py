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


DEEPGRAM_URL = (
    "wss://api.eu.deepgram.com/v2/listen"
    "?model=flux-general-multi&language_hint=fr&encoding=mulaw&sample_rate=8000"
)


@dataclass(frozen=True)
class Settings:
    foodking_base_url: str
    gateway_id: str
    gateway_secret: str
    deepgram_api_key: str
    ari_url: str
    ari_username: str
    ari_password: str
    employee_endpoint: str
    rtp_advertise_host: str
    rtp_bind_host: str = "0.0.0.0"

    @classmethod
    def from_env(cls) -> "Settings":
        settings = cls(
            foodking_base_url=os.environ.get("VOICE_GATEWAY_FOODKING_BASE_URL", "").rstrip("/"),
            gateway_id=os.environ.get("VOICE_ORDER_GATEWAY_ID", "restaurant-main"),
            gateway_secret=os.environ.get("VOICE_ORDER_GATEWAY_SECRET", ""),
            deepgram_api_key=os.environ.get("VOICE_GATEWAY_DEEPGRAM_API_KEY", ""),
            ari_url=os.environ.get("VOICE_GATEWAY_ARI_URL", "http://127.0.0.1:8088/ari").rstrip("/"),
            ari_username=os.environ.get("VOICE_GATEWAY_ARI_USERNAME", "foodking"),
            ari_password=os.environ.get("VOICE_GATEWAY_ARI_PASSWORD", ""),
            employee_endpoint=os.environ.get("VOICE_GATEWAY_EMPLOYEE_ENDPOINT", "PJSIP/100"),
            rtp_advertise_host=os.environ.get("VOICE_GATEWAY_RTP_ADVERTISE_HOST", "127.0.0.1"),
            rtp_bind_host=os.environ.get("VOICE_GATEWAY_RTP_BIND_HOST", "0.0.0.0"),
        )
        required = {
            "VOICE_GATEWAY_FOODKING_BASE_URL": settings.foodking_base_url,
            "VOICE_ORDER_GATEWAY_SECRET": settings.gateway_secret,
            "VOICE_GATEWAY_DEEPGRAM_API_KEY": settings.deepgram_api_key,
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


class DeepgramStream:
    def __init__(self, session: aiohttp.ClientSession, key: str, foodking: FoodKingClient, call_id: str) -> None:
        self.session = session
        self.key = key
        self.foodking = foodking
        self.call_id = call_id
        self.queue: asyncio.Queue[bytes] = asyncio.Queue(maxsize=40)
        self.ws: aiohttp.ClientWebSocketResponse | None = None
        self.tasks: list[asyncio.Task[Any]] = []
        self.last_final_turn: str | None = None

    async def start(self) -> None:
        # This method is called only after FoodKing returned media_authorized=true.
        self.ws = await self.session.ws_connect(
            DEEPGRAM_URL,
            headers={"Authorization": f"Token {self.key}"},
            heartbeat=15,
            timeout=8,
        )
        self.tasks = [
            asyncio.create_task(self._send_audio()),
            asyncio.create_task(self._receive_events()),
        ]

    async def close(self) -> None:
        sender = self.tasks[0] if self.tasks else None
        receiver = self.tasks[1] if len(self.tasks) > 1 else None
        if sender:
            sender.cancel()
            await asyncio.gather(sender, return_exceptions=True)

        if self.ws and not self.ws.closed:
            # Flux traite les messages dans l'ordre : terminer le tour courant,
            # vider l'audio restant, recevoir EndOfTurn, puis fermer proprement.
            try:
                await self.ws.send_json({"type": "ForceEndTurn"})
                await self.ws.send_json({"type": "CloseStream"})
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

    async def _send_audio(self) -> None:
        assert self.ws is not None
        while True:
            await self.ws.send_bytes(await self.queue.get())

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
            event = payload.get("event")
            turn_id = str(payload.get("turn_index") or payload.get("id") or uuid.uuid4().hex)
            final = event == "EndOfTurn"
            if final and self.last_final_turn == turn_id:
                continue
            if final:
                self.last_final_turn = turn_id
            await self.foodking.event({
                "event": "transcript.final" if final else "transcript.update",
                "call_id": self.call_id,
                "turn_id": turn_id,
                "speaker": "unknown",
                "text": text,
                "confidence": payload.get("confidence"),
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
    deepgram: DeepgramStream | None = None
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
        # No pre-consent packets exist: the UDP listener and Deepgram socket are created here.
        deepgram = DeepgramStream(self.session, self.settings.deepgram_api_key, self.foodking, call.call_id)
        transport, _ = await asyncio.get_running_loop().create_datagram_endpoint(
            lambda: RtpReceiver(deepgram.queue),
            local_addr=(self.settings.rtp_bind_host, 0),
        )
        try:
            call.rtp_transport = transport
            port = transport.get_extra_info("sockname")[1]
            await deepgram.start()
            call.deepgram = deepgram
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
            await deepgram.close()
            transport.close()
            call.deepgram = None
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
        if call.deepgram:
            await call.deepgram.close()
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
