#!/usr/bin/env python3
"""Generate the French webform-node translation payload from production."""

from __future__ import annotations

import html
import json
import re
import time
import urllib.parse
import urllib.request
from pathlib import Path


SOURCE_URL = "https://stinchcombelist.com/jsonapi/node/webform?page%5Blimit%5D=50"
TRANSLATE_URL = (
    "https://translate.googleapis.com/translate_a/single"
    "?client=gtx&sl=en&tl=fr&dt=t"
)
OUTPUT = (
    Path(__file__).resolve().parents[1]
    / "web/modules/custom/stinchcombe_content_types/translations/fr/webform_nodes.json"
)

TITLES = {
    7: "Ajouter une personne",
    8: "Déposer une plainte contre un agent de police",
    9: "Déposer une plainte contre un procureur",
    10: "Déposer une plainte contre un juge",
    11: "Déposer une plainte contre un agent public",
    12: "Demander un retrait",
}

ALIASES = {
    7: "/ajouter-une-personne",
    8: "/deposer-une-plainte-contre-un-agent-de-police",
    9: "/deposer-une-plainte-contre-un-procureur",
    10: "/deposer-une-plainte-contre-un-juge",
    11: "/deposer-une-plainte-contre-un-agent-public",
    12: "/demander-un-retrait",
}

TEXT_NODE = re.compile(r"(?<=>)([^<>]+)(?=<)")
MARKER = re.compile(r"ZXQSEG(\d{4})QXZ", re.IGNORECASE)


def request_json(url: str, data: bytes | None = None) -> object:
    request = urllib.request.Request(
        url,
        data=data,
        headers={
            "Accept": "application/vnd.api+json, application/json",
            "User-Agent": "Stinchcombe-translation-generator/1.0",
        },
    )
    for attempt in range(6):
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                return json.load(response)
        except Exception:
            if attempt == 5:
                raise
            time.sleep(2**attempt)
    raise RuntimeError("unreachable")


def translate_batch(values: list[str]) -> list[str]:
    joined = "\n".join(f"ZXQSEG{i:04d}QXZ {value}" for i, value in enumerate(values))
    data = urllib.parse.urlencode({"q": html.unescape(joined)}).encode()
    result = request_json(TRANSLATE_URL, data)
    translated = "".join(part[0] for part in result[0] if part and part[0])
    matches = list(MARKER.finditer(translated))
    if len(matches) != len(values):
        raise RuntimeError(
            f"Translation marker mismatch: expected {len(values)}, got {len(matches)}"
        )
    output: list[str] = []
    for index, match in enumerate(matches):
        end = matches[index + 1].start() if index + 1 < len(matches) else len(translated)
        output.append(translated[match.end() : end])
    for index, value in enumerate(values):
        if not output[index].strip():
            output[index] = translate_text(value)
    return output


def translate_text(value: str) -> str:
    data = urllib.parse.urlencode({"q": html.unescape(value)}).encode()
    result = request_json(TRANSLATE_URL, data)
    translated = "".join(part[0] for part in result[0] if part and part[0])
    if not translated.strip():
        raise RuntimeError(f"Translation service returned an empty value for {value!r}")
    return translated


def translate_values(values: list[str]) -> list[str]:
    translated: list[str] = []
    batch: list[str] = []
    size = 0
    for value in values:
        if batch and size + len(value) > 3500:
            translated.extend(translate_batch(batch))
            batch = []
            size = 0
            time.sleep(0.15)
        batch.append(value)
        size += len(value) + 6
    if batch:
        translated.extend(translate_batch(batch))
    return translated


def translate_html(value: str) -> str:
    matches = [match for match in TEXT_NODE.finditer(value) if match.group(1).strip()]
    source = [match.group(1) for match in matches]
    translated = translate_values(source)
    pieces: list[str] = []
    cursor = 0
    for match, replacement in zip(matches, translated, strict=True):
        pieces.append(value[cursor : match.start()])
        leading = re.match(r"^\s*", match.group(1)).group(0)
        trailing = re.search(r"\s*$", match.group(1)).group(0)
        pieces.append(leading + html.escape(replacement.strip(), quote=False) + trailing)
        cursor = match.end()
    pieces.append(value[cursor:])
    return "".join(pieces)


def main() -> None:
    document = request_json(SOURCE_URL)
    nodes = sorted(document["data"], key=lambda item: item["attributes"]["drupal_internal__nid"])
    found = {item["attributes"]["drupal_internal__nid"] for item in nodes}
    if found != set(TITLES):
        raise RuntimeError(f"Expected node IDs {sorted(TITLES)}, found {sorted(found)}")

    payload: dict[str, dict[str, str]] = {}
    for node in nodes:
        attributes = node["attributes"]
        nid = attributes["drupal_internal__nid"]
        print(f"Translating node {nid}: {attributes['title']}", flush=True)
        body = attributes["body"]
        payload[str(nid)] = {
            "source_title": attributes["title"],
            "title": TITLES[nid],
            "alias": ALIASES[nid],
            "summary": translate_html(f"<p>{html.escape(body['summary'])}</p>")[3:-4],
            "body": translate_html(body["value"]),
        }

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"Wrote {OUTPUT}")


if __name__ == "__main__":
    main()
