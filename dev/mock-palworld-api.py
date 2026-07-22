#!/usr/bin/env python3
"""Mock Palworld REST API for plugin development.

Serves the official /v1/api endpoints with fixture data on port 8212.
Basic auth: admin / devpassword. Kick/ban actually remove the player so
the panel UI feedback loop feels real. State resets on restart.
"""
import base64
import json
import time
from http.server import BaseHTTPRequestHandler, HTTPServer

PASSWORD = "devpassword"
START = time.time()

players = [
    {"name": "Soul", "accountName": "soul", "playerId": "3040478440", "userId": "steam_76561198000000001",
     "ip": "10.0.0.2", "ping": 18.2, "location_x": 220000.0, "location_y": -140000.0, "level": 42, "building_count": 132},
    {"name": "Nyx", "accountName": "nyx_gaming", "playerId": "1284772001", "userId": "steam_76561198000000002",
     "ip": "10.0.0.3", "ping": 31.7, "location_x": -95000.0, "location_y": 51000.0, "level": 35, "building_count": 87},
    {"name": "Bricktop", "accountName": "brick", "playerId": "886402113", "userId": "steam_76561198000000003",
     "ip": "10.0.0.4", "ping": 55.0, "location_x": 158000.0, "location_y": 372000.0, "level": 27, "building_count": 12},
]


class Handler(BaseHTTPRequestHandler):
    def _authed(self):
        auth = self.headers.get("Authorization", "")
        return auth == "Basic " + base64.b64encode(f"admin:{PASSWORD}".encode()).decode()

    def _send(self, code, payload=None):
        body = json.dumps(payload).encode() if payload is not None else b"OK"
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _body(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b"{}"
        try:
            return json.loads(raw)
        except json.JSONDecodeError:
            return {}

    def do_GET(self):
        if not self._authed():
            return self._send(401, {"error": "unauthorized"})
        if self.path == "/v1/api/players":
            return self._send(200, {"players": players})
        if self.path == "/v1/api/info":
            return self._send(200, {"version": "v0.6.1", "servername": "Palworld Dev", "description": "mock", "worldguid": "0123456789abcdef"})
        if self.path == "/v1/api/metrics":
            return self._send(200, {"serverfps": 59, "currentplayernum": len(players), "serverframetime": 16.9,
                                    "maxplayernum": 32, "uptime": int(time.time() - START), "days": 12})
        if self.path == "/v1/api/settings":
            return self._send(200, {"Difficulty": "None", "DayTimeSpeedRate": 1.0, "ServerPlayerMaxNum": 32})
        return self._send(404, {"error": "not found"})

    def do_POST(self):
        if not self._authed():
            return self._send(401, {"error": "unauthorized"})
        body = self._body()
        global players
        if self.path in ("/v1/api/kick", "/v1/api/ban"):
            uid = body.get("userid", "")
            before = len(players)
            players = [p for p in players if p["userId"] != uid]
            print(f"{self.path} {uid}: {before} -> {len(players)} players")
            return self._send(200)
        if self.path in ("/v1/api/unban", "/v1/api/announce", "/v1/api/save", "/v1/api/shutdown", "/v1/api/stop"):
            print(f"{self.path} {body}")
            return self._send(200)
        return self._send(404, {"error": "not found"})

    def log_message(self, *args):
        pass


if __name__ == "__main__":
    print("mock Palworld REST API on :8212 (admin/devpassword)")
    HTTPServer(("0.0.0.0", 8212), Handler).serve_forever()
