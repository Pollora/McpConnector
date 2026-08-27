#!/usr/bin/env python3
"""A minimal Chrome DevTools Protocol client, enough to screenshot wp-admin.

Written by hand because there is no websocket library on this machine and the
job needs three things a `--screenshot` invocation cannot do: set a cookie, wait
for the page to settle, and capture a clip of an exact pixel size at a chosen
device scale factor.

Only the parts of RFC 6455 a localhost client needs: the handshake, masked text
frames out, unfragmented frames in.
"""
import base64
import json
import os
import re
import socket
import struct
import subprocess
import sys
import time
import urllib.request


class WebSocket:
    def __init__(self, url):
        match = re.match(r"ws://([^:/]+):(\d+)(/.*)", url)
        host, port, path = match.group(1), int(match.group(2)), match.group(3)

        self.sock = socket.create_connection((host, port))
        key = base64.b64encode(os.urandom(16)).decode()

        self.sock.sendall(
            f"GET {path} HTTP/1.1\r\nHost: {host}:{port}\r\n"
            f"Upgrade: websocket\r\nConnection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n".encode()
        )

        buffer = b""
        while b"\r\n\r\n" not in buffer:
            buffer += self.sock.recv(4096)

        if b"101" not in buffer.split(b"\r\n")[0]:
            raise RuntimeError("handshake refused: " + buffer.split(b"\r\n")[0].decode())

        self.rest = buffer.split(b"\r\n\r\n", 1)[1]

    def _read(self, n):
        while len(self.rest) < n:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise RuntimeError("connection closed")
            self.rest += chunk

        out, self.rest = self.rest[:n], self.rest[n:]
        return out

    def send(self, text):
        payload = text.encode()
        header = bytearray([0x81])
        length = len(payload)

        if length < 126:
            header.append(0x80 | length)
        elif length < 65536:
            header.append(0x80 | 126)
            header += struct.pack(">H", length)
        else:
            header.append(0x80 | 127)
            header += struct.pack(">Q", length)

        mask = os.urandom(4)
        header += mask
        masked = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
        self.sock.sendall(bytes(header) + masked)

    def recv(self):
        first, second = self._read(2)
        length = second & 0x7F

        if length == 126:
            length = struct.unpack(">H", self._read(2))[0]
        elif length == 127:
            length = struct.unpack(">Q", self._read(8))[0]

        return self._read(length).decode()


class Chrome:
    def __init__(self, port=9333, profile="/tmp/cdp-profile"):
        self.process = subprocess.Popen([
            "google-chrome", "--headless=new", "--disable-gpu", "--no-sandbox",
            "--hide-scrollbars", "--ignore-certificate-errors",
            f"--remote-debugging-port={port}", f"--user-data-dir={profile}",
            "--window-size=1600,1200", "about:blank",
        ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

        target = None
        for _ in range(80):
            try:
                targets = json.load(urllib.request.urlopen(f"http://127.0.0.1:{port}/json"))
                pages = [t for t in targets if t["type"] == "page"]
                if pages:
                    target = pages[0]
                    break
            except Exception:
                pass
            time.sleep(0.25)

        if target is None:
            raise RuntimeError("Chrome never offered a page target")

        self.ws = WebSocket(target["webSocketDebuggerUrl"])
        self.next_id = 0

    def call(self, method, **params):
        self.next_id += 1
        request_id = self.next_id
        self.ws.send(json.dumps({"id": request_id, "method": method, "params": params}))

        while True:
            message = json.loads(self.ws.recv())
            if message.get("id") == request_id:
                if "error" in message:
                    raise RuntimeError(f"{method}: {message['error']}")
                return message.get("result", {})

    def close(self):
        try:
            self.process.terminate()
            self.process.wait(timeout=10)
        except Exception:
            self.process.kill()


def capture(chrome, url, out, width, height=None, scale=2, settle=2.0, script=None,
            clip_to=None):
    """Screenshot `url` at `width` CSS pixels, `scale`x, into `out`.

    `clip_to` is a CSS selector: the shot is cropped to that element's box
    rather than to the viewport. Measuring the element beats guessing an offset,
    and it is what keeps this installation's admin furniture out of the frame.
    """
    chrome.call("Emulation.setDeviceMetricsOverride",
                width=width, height=height or 1200, deviceScaleFactor=scale, mobile=False)
    chrome.call("Page.enable")
    chrome.call("Page.navigate", url=url)
    time.sleep(settle)

    if script:
        chrome.call("Runtime.evaluate", expression=script, awaitPromise=True)
        time.sleep(0.8)

    metrics = chrome.call("Page.getLayoutMetrics")
    content_height = height or min(4000, int(metrics["cssContentSize"]["height"]))

    chrome.call("Emulation.setDeviceMetricsOverride",
                width=width, height=content_height, deviceScaleFactor=scale, mobile=False)
    time.sleep(0.4)

    box = {"x": 0, "y": 0, "width": width, "height": content_height, "scale": 1}

    if clip_to:
        measured = chrome.call("Runtime.evaluate", returnByValue=True, expression=f"""
            (() => {{
              const el = document.querySelector({clip_to!r});
              if (!el) return null;
              const r = el.getBoundingClientRect();
              return {{x: r.left + scrollX, y: r.top + scrollY,
                       width: r.width, height: r.height}};
            }})()
        """)["result"].get("value")

        if not measured:
            raise RuntimeError(f"{url}: nothing matched {clip_to}")

        box = {**measured, "scale": 1}
        box["height"] = min(box["height"], content_height - box["y"])

    shot = chrome.call("Page.captureScreenshot", format="png", captureBeyondViewport=True,
                       clip=box)

    with open(out, "wb") as handle:
        handle.write(base64.b64decode(shot["data"]))

    from PIL import Image
    image = Image.open(out)
    print(f"{os.path.basename(out)}  {image.size[0]}x{image.size[1]}")
    return image.size


if __name__ == "__main__":
    print("cdp.py is a library; see shoot.py")
    sys.exit(0)
