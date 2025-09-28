#!/usr/bin/env python3
"""Validate cookie-related matrices live solely in §7.1."""
from __future__ import annotations

import sys
from pathlib import Path

import generate_spec_sections as spec_sections

ROOT = Path(__file__).resolve().parent.parent
SPEC_PATH = ROOT / "docs" / "electronic_forms_SPEC.md"
COOKIE_HEADERS_INCLUDE_PATH = ROOT / "docs" / "generated" / "security" / "cookie_headers.md"

TARGET_ANCHORS = [
    "sec-cookie-policy-matrix",
    "sec-cookie-lifecycle-matrix",
    "sec-cookie-ncid-summary",
    "sec-cookie-header-actions",
]
EXPECTED_ROW_IDS = {
    "cookie_policy_rows": {
        "cookie-policy-hard",
        "cookie-policy-soft",
        "cookie-policy-off",
        "cookie-policy-challenge",
    },
    "cookie_lifecycle_rows": {
        "cookie-lifecycle-get-slotless",
        "cookie-lifecycle-get-slotted",
        "cookie-lifecycle-prime",
        "cookie-lifecycle-slots-disabled-global",
        "cookie-lifecycle-post-slotless",
        "cookie-lifecycle-post-slotted",
        "cookie-lifecycle-error-rerender",
        "cookie-lifecycle-challenge-rerender",
        "cookie-lifecycle-challenge-success",
    },
    "lifecycle_quickstart_rows": {
        "quickstart-render",
        "quickstart-persist",
        "quickstart-post-gate",
        "quickstart-challenge",
        "quickstart-normalize",
        "quickstart-ledger",
        "quickstart-success",
    },
    "ncid_summary_rows": {
        "ncid-summary-hidden-valid",
        "ncid-summary-hidden-missing",
        "ncid-summary-policy-hard",
        "ncid-summary-policy-soft",
        "ncid-summary-policy-off",
        "ncid-summary-policy-challenge",
        "ncid-summary-challenge-rerender",
        "ncid-summary-challenge-success",
        "ncid-summary-success-handoff",
    },
    "ncid_rerender_steps": {
        "ncid-rerender-delete-cookie",
        "ncid-rerender-reprime",
        "ncid-rerender-pinned-submission",
        "ncid-rerender-challenge-verify",
    },
    "cookie_header_actions_rows": {
        "cookie-header-get-render",
        "cookie-header-prime",
        "cookie-header-post-rerender",
        "cookie-header-challenge-success",
        "cookie-header-prg-redirect",
    },
}
TABLE_HEADERS = {
    "sec-cookie-policy-matrix": (SPEC_PATH, "| Policy path | Handling when cookie missing/invalid or record expired | `token_ok` | Soft labels | `require_challenge` | Identifier returned | `cookie_present?` |"),
    "sec-cookie-lifecycle-matrix": (SPEC_PATH, "| Flow trigger | Server MUST | Identifier outcome | Notes |"),
    "sec-cookie-ncid-summary": (SPEC_PATH, "| Scenario | Identifier outcome | Required action | Canonical section |"),
    "sec-cookie-header-actions": (COOKIE_HEADERS_INCLUDE_PATH, "| Flow trigger | Header action | Invariants |"),
}

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

        header_info = TABLE_HEADERS.get(anchor)
        if header_info:
            header_path, header = header_info
            if header_path == SPEC_PATH:
                search_lines = lines
            else:
                try:
                    include_text = header_path.read_text(encoding="utf-8")
                except FileNotFoundError:
                    errors.append(f"Table file not found: {header_path}")
                    continue
                search_lines = include_text.splitlines()
            header_matches = [
                idx
                for idx, raw_line in enumerate(search_lines)
                if raw_line.strip().startswith(header)
            ]
            if not header_matches:
                try:
                    display_path = header_path.relative_to(ROOT)
                except ValueError:
                    display_path = header_path
                errors.append(
                    f"Expected table header for #{anchor} not found in {display_path}"
                )
                continue
            if len(header_matches) > 1:
                human_lines = ", ".join(str(idx + 1) for idx in header_matches)
                errors.append(
                    f"Table header for #{anchor} appears multiple times (lines {human_lines})"
                )
            if header_path == SPEC_PATH:
                for idx in header_matches:
                    if not (section_start <= idx < section_end):
                        errors.append(
                            f"Table for #{anchor} (detected at line {idx + 1}) is outside §7.1"
                        )
    return errors


def validate_security_data() -> list[str]:
    errors: list[str] = []
    try:
        data = spec_sections.load_data()
    except SystemExit as exc:  # surface schema issues as lint failures
        errors.append(str(exc))
        return errors

    for key, expected in EXPECTED_ROW_IDS.items():
        rows = data.get(key, [])
        actual = {row.get("id") for row in rows}
        missing = expected - actual
        extra = actual - expected
        if missing:
            errors.append(
                f"{key} missing row ids: {', '.join(sorted(missing))}"
            )
        if extra:
            errors.append(
                f"{key} contains unknown row ids: {', '.join(sorted(extra))}"
            )
    return errors


def main() -> int:
    lines = read_spec()
    errors = []
    errors.extend(validate_security_data())
    errors.extend(ensure_tables_within_section(lines))

    if errors:
        for message in errors:
            print(f"ERROR: {message}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
