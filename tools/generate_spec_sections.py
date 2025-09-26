#!/usr/bin/env python3
"""Generate security spec tables from YAML data."""

import argparse
import sys
from pathlib import Path

import yaml

SPEC_PATH = Path("docs/electronic_forms_SPEC.md")
DATA_PATH = Path("tools/spec_sources/security_data.yaml")
POINTER_TEXT = "**Generated from `tools/spec_sources/security_data.yaml` — do not edit manually.**"

TABLE_CONFIGS = [
    {
        "name": "cookie-lifecycle-matrix",
        "data_key": "cookie_lifecycle_rows",
        "indent": 40,
        "header_token": "| Flow trigger | Server MUST | Identifier outcome | Notes |",
        "header": "| Flow trigger | Server MUST | Identifier outcome | Notes |",
        "separator": "|--------------|-------------|--------------------|-------|",
        "columns": [
            ("flow_trigger", "Flow trigger"),
            ("server_must", "Server MUST"),
            ("identifier", "Identifier outcome"),
            ("notes", "Notes"),
        ],
    },
    {
        "name": "cookie-policy-matrix",
        "data_key": "cookie_policy_rows",
        "indent": 40,
        "header_token": "| Policy path | Handling when cookie missing/invalid or record expired | `token_ok` | Soft labels | `require_challenge` | Identifier returned | `cookie_present?` |",
        "header": "| Policy path | Handling when cookie missing/invalid or record expired | `token_ok` | Soft labels | `require_challenge` | Identifier returned | `cookie_present?` |",
        "separator": "|-------------|-----------------------------------------------------|-----------|-------------|--------------------|--------------------|-------------------|",
        "columns": [
            ("policy_path", "Policy path"),
            ("handling", "Handling when cookie missing/invalid or record expired"),
            ("token_ok", "`token_ok`"),
            ("soft_labels", "Soft labels"),
            ("require_challenge", "`require_challenge`"),
            ("identifier", "Identifier returned"),
            ("cookie_present", "`cookie_present?`"),
        ],
    },
    {
        "name": "cookie-ncid-summary",
        "data_key": "ncid_summary_rows",
        "indent": 0,
        "header_token": "| Scenario | Identifier outcome | Required action | Canonical section |",
        "header": "| Scenario | Identifier outcome | Required action | Canonical section |",
        "separator": "|----------|--------------------|-----------------|-------------------|",
        "columns": [
            ("scenario", "Scenario"),
            ("identifier_outcome", "Identifier outcome"),
            ("required_action", "Required action"),
            ("canonical_section", "Canonical section"),
        ],
    },
]


def load_data() -> dict:
    if not DATA_PATH.exists():
        raise SystemExit(f"Missing data file: {DATA_PATH}")
    return yaml.safe_load(DATA_PATH.read_text(encoding="utf-8"))


def ensure_references(data: dict, spec_text: str) -> None:
    """Basic smoke checks tying rows back to anchors and flows."""
    anchors_required = set()

    # Collect anchors directly referenced in the doc to confirm they exist.
    def check_anchor(anchor: str, context: str) -> None:
        if anchor.startswith("#"):
            target = anchor[1:]
            patterns = [
                anchor,
                f"(#{target})",
                f"id=\"{target}\"",
            ]
        else:
            patterns = [anchor]
        if not any(token in spec_text for token in patterns):
            raise SystemExit(f"Anchor {anchor} referenced by {context} not found in spec")

    # Cookie policy rows must cover the canonical policy paths and surface NCID usage.
    policy_rows = data.get("cookie_policy_rows", [])
    expected_policies = {"`hard`", "`soft`", "`off`", "`challenge`"}
    actual_policies = {row["policy_path"] for row in policy_rows}
    if actual_policies != expected_policies:
        missing = expected_policies - actual_policies
        extra = actual_policies - expected_policies
        details = []
        if missing:
            details.append(f"missing {sorted(missing)}")
        if extra:
            details.append(f"unexpected {sorted(extra)}")
        raise SystemExit(f"Cookie policy rows mismatch: {', '.join(details)}")
    for row in policy_rows:
        for anchor in row.get("references", []):
            anchors_required.add((anchor, f"policy {row['policy_path']}") )
        # Minimal semantic guardrails to keep data-first narrative intact.
        if row["policy_path"] == "`hard`" and "nc-" in row["identifier"]:
            raise SystemExit("Hard policy must not mint NCIDs")
        if row["policy_path"] != "`hard`" and "nc-" not in row["identifier"]:
            raise SystemExit(f"Policy {row['policy_path']} must reference NCID outcome")
        if row["policy_path"] == "`challenge`" and "`true`" not in row["require_challenge"]:
            raise SystemExit("Challenge policy must require challenge")

    # Lifecycle rows: ensure coverage for nominal flows.
    lifecycle_rows = data.get("cookie_lifecycle_rows", [])
    lifecycle_map = {row["flow_trigger"]: row for row in lifecycle_rows}
    lifecycle_sequences = [
        ["GET render (slots disabled)", "`/eforms/prime` request", "POST from slotless render"],
        ["GET render (slots enabled)", "`/eforms/prime` request", "POST from slotted render"],
        ["Slots disabled globally"],
        ["Error rerender after NCID fallback", "Challenge rerender (before verification)", "Challenge success response"],
    ]
    covered = set()
    for sequence in lifecycle_sequences:
        for step in sequence:
            if step not in lifecycle_map:
                raise SystemExit(f"Lifecycle step '{step}' missing from YAML")
            covered.add(step)
    missing_triggers = set(lifecycle_map) - covered
    if missing_triggers:
        raise SystemExit(f"Lifecycle rows not exercised by smoke flows: {sorted(missing_triggers)}")
    for row in lifecycle_rows:
        for anchor in row.get("references", []):
            anchors_required.add((anchor, f"lifecycle {row['flow_trigger']}") )

    # Summary rows: ensure canonical anchors exist.
    for row in data.get("ncid_summary_rows", []):
        canonical = row.get("canonical_section", "")
        if "(#" in canonical:
            anchor = canonical.split("(#", 1)[1].split(")", 1)[0]
            anchors_required.add((f"#{anchor}", f"summary {row['scenario']}") )

    for anchor, context in anchors_required:
        check_anchor(anchor, context)


def render_table(config: dict, rows: list[dict]) -> list[str]:
    lines = [config["header"], config["separator"]]
    column_order = [field for field, _ in config["columns"]]
    for row in rows:
        cells = [row[field] for field in column_order]
        line = "| " + " | ".join(cells) + " |"
        lines.append(line)
    indent = " " * config["indent"]
    prefixed = [indent + line if line else line for line in lines]
    block = [indent + POINTER_TEXT, indent + f"<!-- BEGIN GENERATED: {config['name']} -->"]
    block.extend(prefixed)
    block.append(indent + f"<!-- END GENERATED: {config['name']} -->")
    return block


def integrate_tables(data: dict, *, check: bool) -> bool:
    spec_text = SPEC_PATH.read_text(encoding="utf-8")
    ensure_references(data, spec_text)
    lines = spec_text.splitlines()
    updated = lines[:]

    for config in TABLE_CONFIGS:
        rows = data[config["data_key"]]
        rendered_block = render_table(config, rows)
        indent = " " * config["indent"]
        pointer_line = indent + POINTER_TEXT
        begin_marker = indent + f"<!-- BEGIN GENERATED: {config['name']} -->"
        end_marker = indent + f"<!-- END GENERATED: {config['name']} -->"

        if begin_marker in spec_text:
            # Replace from pointer line through end marker.
            start_idx = None
            end_idx = None
            for idx, line in enumerate(updated):
                if line == pointer_line and updated[idx + 1] == begin_marker:
                    start_idx = idx
                    break
            if start_idx is None:
                raise SystemExit(f"Pointer line for {config['name']} not found")
            for idx in range(start_idx, len(updated)):
                if updated[idx] == end_marker:
                    end_idx = idx
                    break
            if end_idx is None:
                raise SystemExit(f"End marker for {config['name']} not found")
            end_idx += 1  # include end marker line
        else:
            # Locate the original table using the header token.
            header_token = config["header_token"].strip()
            start_idx = None
            for idx, line in enumerate(updated):
                if line.strip() == header_token:
                    start_idx = idx
                    break
            if start_idx is None:
                raise SystemExit(f"Table header for {config['name']} not found")
            end_idx = start_idx
            while end_idx < len(updated) and updated[end_idx].strip().startswith("|"):
                end_idx += 1
        updated[start_idx:end_idx] = rendered_block

    new_text = "\n".join(updated) + "\n"
    if check:
        if new_text != spec_text:
            sys.stderr.write("Spec sections are stale. Run tools/generate_spec_sections.py to update.\n")
            return False
        return True
    SPEC_PATH.write_text(new_text, encoding="utf-8")
    return True


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate security spec matrices")
    parser.add_argument("--check", action="store_true", help="Verify spec matches generated tables")
    args = parser.parse_args()

    data = load_data()
    ok = integrate_tables(data, check=args.check)
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
