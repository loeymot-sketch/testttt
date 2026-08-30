from __future__ import annotations


def rtp_payload(packet: bytes) -> bytes:
    """Extract an RTP payload, including CSRC and RFC3550 header extensions."""
    if len(packet) < 12 or packet[0] >> 6 != 2:
        return b""
    padding = bool(packet[0] & 0x20)
    extension = bool(packet[0] & 0x10)
    csrc_count = packet[0] & 0x0F
    offset = 12 + (csrc_count * 4)
    if offset > len(packet):
        return b""
    if extension:
        if offset + 4 > len(packet):
            return b""
        words = int.from_bytes(packet[offset + 2:offset + 4], "big")
        offset += 4 + (words * 4)
        if offset > len(packet):
            return b""
    end = len(packet)
    if padding:
        pad = packet[-1]
        if pad <= 0 or pad > end - offset:
            return b""
        end -= pad
    return packet[offset:end]


class AudioBatcher:
    """Batch four 20 ms PCMU RTP frames into the ~80 ms Deepgram cadence."""

    def __init__(self, target_bytes: int = 640) -> None:
        self.target_bytes = target_bytes
        self._buffer = bytearray()

    def feed(self, payload: bytes) -> list[bytes]:
        if payload:
            self._buffer.extend(payload)
        batches: list[bytes] = []
        while len(self._buffer) >= self.target_bytes:
            batches.append(bytes(self._buffer[:self.target_bytes]))
            del self._buffer[:self.target_bytes]
        return batches

    def flush(self) -> bytes:
        remaining = bytes(self._buffer)
        self._buffer.clear()
        return remaining
