#!/usr/bin/env python3
"""Dependency-free MCP bridge for the Stinchcombe List Drupal JSON:API."""

from __future__ import annotations

import base64
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from typing import Any


BASE_URL = os.getenv("STINCHCOMBE_JSONAPI_BASE_URL", "https://stinchcombelist.com").rstrip("/")
USERNAME = os.getenv("STINCHCOMBE_JSONAPI_USERNAME", "")
PASSWORD = os.getenv("STINCHCOMBE_JSONAPI_PASSWORD", "")
JSONAPI_MEDIA_TYPE = "application/vnd.api+json"


def _resource_path(resource_type: str, resource_bundle: str, resource_id: str | None = None) -> str:
    for value in (resource_type, resource_bundle):
        if not value or not value.replace("_", "").replace("-", "").isalnum():
            raise ValueError("Resource type and bundle contain an invalid character.")
    path = f"/jsonapi/{resource_type}/{resource_bundle}"
    if resource_id:
        if not all(char.isalnum() or char in "-_" for char in resource_id):
            raise ValueError("Invalid resource UUID.")
        path += f"/{resource_id}"
    return path


def _request(method: str, path: str, query: dict[str, Any] | None = None, body: Any = None) -> Any:
    url = BASE_URL + path
    if query:
        pairs: list[tuple[str, str]] = []
        for key, value in query.items():
            if value is None or value == "":
                continue
            if isinstance(value, dict):
                pairs.extend((f"filter[{subkey}]", str(subvalue)) for subkey, subvalue in value.items())
            else:
                pairs.append((key, str(value)))
        url += "?" + urllib.parse.urlencode(pairs)

    headers = {"Accept": JSONAPI_MEDIA_TYPE, "User-Agent": "stinchcombe-codex-mcp/1.0"}
    data = None
    if body is not None:
        data = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = JSONAPI_MEDIA_TYPE
    if USERNAME and PASSWORD:
        token = base64.b64encode(f"{USERNAME}:{PASSWORD}".encode()).decode("ascii")
        headers["Authorization"] = f"Basic {token}"

    request = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            payload = response.read()
            return json.loads(payload) if payload else {"status": response.status}
    except urllib.error.HTTPError as error:
        details = error.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"JSON:API returned HTTP {error.code}: {details[:2000]}") from error
    except urllib.error.URLError as error:
        raise RuntimeError(f"Could not reach {BASE_URL}: {error.reason}") from error


def _require_credentials() -> None:
    if not USERNAME or not PASSWORD:
        raise PermissionError(
            "Writes are disabled. Set STINCHCOMBE_JSONAPI_USERNAME and "
            "STINCHCOMBE_JSONAPI_PASSWORD in .codex/jsonapi.env."
        )


TOOLS = [
    {
        "name": "discover",
        "description": "Discover JSON:API resource types exposed by the Stinchcombe List.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "list_resources",
        "description": "List and filter Drupal JSON:API resources.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "resource_type": {"type": "string", "description": "Entity type, for example node."},
                "resource_bundle": {"type": "string", "description": "Bundle, for example article."},
                "filters": {"type": "object", "description": "Simple field=value filters."},
                "fields": {"type": "string", "description": "Comma-separated sparse field list."},
                "include": {"type": "string", "description": "Comma-separated relationships."},
                "sort": {"type": "string"},
                "page_limit": {"type": "integer", "minimum": 1, "maximum": 50, "default": 25},
                "page_offset": {"type": "integer", "minimum": 0, "default": 0},
            },
            "required": ["resource_type", "resource_bundle"],
            "additionalProperties": False,
        },
    },
    {
        "name": "get_resource",
        "description": "Fetch one Drupal JSON:API resource by UUID.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "resource_type": {"type": "string"},
                "resource_bundle": {"type": "string"},
                "resource_id": {"type": "string"},
                "include": {"type": "string"},
            },
            "required": ["resource_type", "resource_bundle", "resource_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "create_resource",
        "description": "Create a resource. Requires local credentials and Drupal permissions.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "resource_type": {"type": "string"}, "resource_bundle": {"type": "string"},
                "attributes": {"type": "object"}, "relationships": {"type": "object"},
            },
            "required": ["resource_type", "resource_bundle", "attributes"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_resource",
        "description": "Update a resource. Requires local credentials and Drupal permissions.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "resource_type": {"type": "string"}, "resource_bundle": {"type": "string"},
                "resource_id": {"type": "string"}, "attributes": {"type": "object"},
                "relationships": {"type": "object"},
            },
            "required": ["resource_type", "resource_bundle", "resource_id", "attributes"],
            "additionalProperties": False,
        },
    },
    {
        "name": "delete_resource",
        "description": "Delete a resource. Requires local credentials and Drupal permissions.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "resource_type": {"type": "string"}, "resource_bundle": {"type": "string"},
                "resource_id": {"type": "string"},
            },
            "required": ["resource_type", "resource_bundle", "resource_id"],
            "additionalProperties": False,
        },
    },
]


def _call_tool(name: str, arguments: dict[str, Any]) -> Any:
    if name == "discover":
        return _request("GET", "/jsonapi")
    if name not in {tool["name"] for tool in TOOLS}:
        raise ValueError(f"Unknown tool: {name}")

    resource_type = arguments["resource_type"]
    bundle = arguments["resource_bundle"]
    resource_id = arguments.get("resource_id")
    path = _resource_path(resource_type, bundle, resource_id)
    if name == "list_resources":
        query = {
            "filter": arguments.get("filters"),
            f"fields[{resource_type}--{bundle}]": arguments.get("fields"),
            "include": arguments.get("include"), "sort": arguments.get("sort"),
            "page[limit]": arguments.get("page_limit", 25),
            "page[offset]": arguments.get("page_offset", 0),
        }
        return _request("GET", path, query=query)
    if name == "get_resource":
        return _request("GET", path, query={"include": arguments.get("include")})

    _require_credentials()
    if name == "delete_resource":
        return _request("DELETE", path)
    data: dict[str, Any] = {
        "type": f"{resource_type}--{bundle}", "attributes": arguments["attributes"]
    }
    if arguments.get("relationships"):
        data["relationships"] = arguments["relationships"]
    if resource_id:
        data["id"] = resource_id
    return _request("POST" if name == "create_resource" else "PATCH", path, body={"data": data})


def _reply(request_id: Any, result: Any = None, error: dict[str, Any] | None = None) -> None:
    message: dict[str, Any] = {"jsonrpc": "2.0", "id": request_id}
    message["error" if error else "result"] = error if error else result
    sys.stdout.write(json.dumps(message, separators=(",", ":")) + "\n")
    sys.stdout.flush()


def _read_message() -> dict[str, Any] | None:
    while True:
        line = sys.stdin.readline()
        if not line:
            return None
        if line.strip():
            return json.loads(line)


def main() -> None:
    while True:
        message = _read_message()
        if message is None:
            return
        request_id, method = message.get("id"), message.get("method")
        if request_id is None:
            continue
        try:
            if method == "initialize":
                result = {
                    "protocolVersion": message.get("params", {}).get("protocolVersion", "2025-03-26"),
                    "capabilities": {"tools": {}},
                    "serverInfo": {"name": "stinchcombe-jsonapi", "version": "1.0.0"},
                }
            elif method == "ping":
                result = {}
            elif method == "tools/list":
                result = {"tools": TOOLS}
            elif method == "tools/call":
                params = message.get("params", {})
                value = _call_tool(params.get("name", ""), params.get("arguments", {}))
                result = {"content": [{"type": "text", "text": json.dumps(value, indent=2)}]}
            else:
                _reply(request_id, error={"code": -32601, "message": f"Method not found: {method}"})
                continue
            _reply(request_id, result=result)
        except Exception as error:
            if method == "tools/call":
                _reply(request_id, result={"content": [{"type": "text", "text": str(error)}], "isError": True})
            else:
                _reply(request_id, error={"code": -32603, "message": str(error)})


if __name__ == "__main__":
    main()
