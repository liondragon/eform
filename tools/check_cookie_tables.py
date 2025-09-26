#!/usr/bin/env python3
"""Check that cookie-related matrices stay in §7.1 and appendix stubs stay pointers."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SPEC_PATH = ROOT / "docs" / "electronic_forms_SPEC.md"

TARGET_ANCHORS = [
    "sec-cookie-policy-matrix",
    "sec-cookie-lifecycle-matrix",
    "sec-cookie-ncid-summary",
]
TABLE_HEADERS = {
    "sec-cookie-policy-matrix": "| Policy path | Handling when cookie missing/invalid or record expired | `token_ok` | Soft labels | `require_challenge` | Identifier returned | `cookie_present?` |",
    "sec-cookie-lifecycle-matrix": "| Flow trigger | Server MUST | Identifier outcome | Notes |",
    "sec-cookie-ncid-summary": "| Scenario | Identifier outcome | Required action | Canonical section |",
}
APPENDIX_STUBS = [
    "sec-app-cookie-policy",
    "sec-app-cookie-lifecycle",
    "sec-app-cookie-ncid",
]
APPENDIX_TERMINATOR = "sec-past-decisions"


def read_spec() -> list[str]:
    try:
        text = SPEC_PATH.read_text(encoding="utf-8")
    except FileNotFoundError:
        raise SystemExit(f"Spec file not found: {SPEC_PATH}")
    return text.splitlines()


def find_anchor(lines: list[str], anchor: str) -> list[int]:
    needle = f'id="{anchor}"'
    return [idx for idx, line in enumerate(lines) if needle in line]


def require_single_anchor(lines: list[str], anchor: str) -> int:
    matches = find_anchor(lines, anchor)
    if not matches:
        raise SystemExit(f"Missing anchor #{anchor} in {SPEC_PATH}")
    if len(matches) > 1:
        human_lines = ", ".join(str(idx + 1) for idx in matches)
        raise SystemExit(f"Anchor #{anchor} appears multiple times (lines {human_lines})")
    return matches[0]


def ensure_tables_within_section(lines: list[str]) -> list[str]:
    errors: list[str] = []
    section_start = require_single_anchor(lines, "sec-submission-protection")
    section_end = require_single_anchor(lines, "sec-honeypot")
    if section_end <= section_start:
        errors.append("Unable to determine §7.1 boundaries: honeypot section precedes submission protection")
        return errors

    for anchor in TARGET_ANCHORS:
        anchor_line = require_single_anchor(lines, anchor)
        if not (section_start <= anchor_line < section_end):
            errors.append(
                f"Anchor #{anchor} is outside §7.1 (line {anchor_line + 1}); matrices must live under Submission Protection"
            )

        header = TABLE_HEADERS.get(anchor)
        if header:
            for idx, raw_line in enumerate(lines):
                stripped = raw_line.strip()
                if stripped.startswith(header):
                    if not (section_start <= idx < section_end):
                        errors.append(
                            f"Table for #{anchor} (detected at line {idx + 1}) is outside §7.1"
                        )
    return errors


def ensure_appendix_stubs_are_pointers(lines: list[str]) -> list[str]:
    errors: list[str] = []
    appendix_bounds: dict[str, tuple[int, int]] = {}

    ordered_anchors = APPENDIX_STUBS + [APPENDIX_TERMINATOR]
    for i, anchor in enumerate(ordered_anchors[:-1]):
        start = require_single_anchor(lines, anchor)
        end_anchor = ordered_anchors[i + 1]
        end = require_single_anchor(lines, end_anchor)
        if end <= start:
            errors.append(
                f"Appendix section #{anchor} overlaps or is out of order with #{end_anchor}"
            )
            continue
        appendix_bounds[anchor] = (start + 1, end)

    for anchor, (start, end) in appendix_bounds.items():
        for idx in range(start, end):
            stripped = lines[idx].strip()
            if stripped.startswith("|"):
                errors.append(
                    f"Appendix stub #{anchor} must not contain tables (found table row at line {idx + 1})"
                )
    return errors


def main() -> int:
    lines = read_spec()
    errors = []
    errors.extend(ensure_tables_within_section(lines))
    errors.extend(ensure_appendix_stubs_are_pointers(lines))

    if errors:
        for message in errors:
            print(f"ERROR: {message}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
