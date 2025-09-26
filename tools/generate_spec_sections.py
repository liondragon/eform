#!/usr/bin/env python3
"""Generate security spec tables from YAML data."""

import argparse
import re
import sys
from pathlib import Path
from typing import Any, Iterable

import yaml

SPEC_PATH = Path("docs/electronic_forms_SPEC.md")
DATA_PATH = Path("tools/spec_sources/security_data.yaml")
POINTER_TEXT = "**Generated from `tools/spec_sources/security_data.yaml` — do not edit manually.**"
SUPPORTED_SCHEMA_VERSION = 1

POLICY_PATHS = {"hard", "soft", "off", "challenge"}
SOFT_LABEL_VALUES = {"cookie_missing"}
IDENTIFIER_KINDS = {"none", "ncid", "eid", "prime_record", "cookie_record", "submission_id"}
ANCHOR_PATTERN = re.compile(r"\]\(#([^)]+)\)")


def load_data() -> dict:
    if not DATA_PATH.exists():
        raise SystemExit(f"Missing data file: {DATA_PATH}")
    raw = yaml.safe_load(DATA_PATH.read_text(encoding="utf-8"))
    if not isinstance(raw, dict):
        raise SystemExit("Security data must be a mapping at the top level")
    validate_data(raw)
    return raw


def validate_data(data: dict) -> None:
    version = data.get("version")
    if version != SUPPORTED_SCHEMA_VERSION:
        raise SystemExit(
            f"Unsupported security data version: {version!r}; expected {SUPPORTED_SCHEMA_VERSION}"
        )
    schema_uri = data.get("$schema")
    if schema_uri is not None and not isinstance(schema_uri, str):
        raise SystemExit("$schema must be a string when provided")

    row_ids: set[str] = set()

    def ensure_row_id(row: dict, context: str) -> None:
        row_id = row.get("id")
        if not isinstance(row_id, str) or not row_id:
            raise SystemExit(f"Row in {context} is missing a string 'id' field")
        if row_id in row_ids:
            raise SystemExit(f"Duplicate row id detected: {row_id}")
        row_ids.add(row_id)

    cookie_rows = data.get("cookie_policy_rows")
    if not isinstance(cookie_rows, list):
        raise SystemExit("cookie_policy_rows must be a list")
    for row in cookie_rows:
        if not isinstance(row, dict):
            raise SystemExit("cookie_policy_rows entries must be mappings")
        ensure_row_id(row, "cookie_policy_rows")
        policy_path = row.get("policy_path")
        if policy_path not in POLICY_PATHS:
            raise SystemExit(f"Unknown policy_path {policy_path!r}")
        handling = row.get("handling")
        if not isinstance(handling, str):
            raise SystemExit(f"Handling must be a string for policy {policy_path}")
        if not isinstance(row.get("token_ok"), bool):
            raise SystemExit(f"token_ok must be boolean for policy {policy_path}")
        if not isinstance(row.get("require_challenge"), bool):
            raise SystemExit(f"require_challenge must be boolean for policy {policy_path}")
        notes = row.get("notes", "")
        if not isinstance(notes, str):
            raise SystemExit(f"Notes must be a string for policy {policy_path}")
        row.setdefault("notes", notes)
        validate_soft_labels(row.get("soft_labels"), policy_path)
        validate_cookie_presence(row.get("cookie_present"), policy_path, policy_path)
        validate_identifier(row.get("identifier"), f"policy {policy_path}")
        validate_references(row.get("references"), f"policy {policy_path}")

    lifecycle_rows = data.get("cookie_lifecycle_rows")
    if not isinstance(lifecycle_rows, list):
        raise SystemExit("cookie_lifecycle_rows must be a list")
    for row in lifecycle_rows:
        if not isinstance(row, dict):
            raise SystemExit("cookie_lifecycle_rows entries must be mappings")
        ensure_row_id(row, "cookie_lifecycle_rows")
        flow_trigger = row.get("flow_trigger")
        if not isinstance(flow_trigger, str):
            raise SystemExit("flow_trigger must be a string")
        server_must = row.get("server_must")
        if not isinstance(server_must, str) or not server_must:
            raise SystemExit(f"server_must must be a non-empty string for {flow_trigger}")
        validate_identifier(row.get("identifier"), f"lifecycle {flow_trigger}")
        notes = row.get("notes", "")
        if not isinstance(notes, str):
            raise SystemExit(f"Notes must be a string for lifecycle {flow_trigger}")
        row.setdefault("notes", notes)
        validate_references(row.get("references"), f"lifecycle {flow_trigger}")

    summary_rows = data.get("ncid_summary_rows")
    if not isinstance(summary_rows, list):
        raise SystemExit("ncid_summary_rows must be a list")
    for row in summary_rows:
        if not isinstance(row, dict):
            raise SystemExit("ncid_summary_rows entries must be mappings")
        ensure_row_id(row, "ncid_summary_rows")
        scenario = row.get("scenario")
        if not isinstance(scenario, str):
            raise SystemExit("scenario must be a string")
        validate_identifier(row.get("identifier_outcome"), f"summary {scenario}")
        required_action = row.get("required_action")
        if not isinstance(required_action, str) or not required_action:
            raise SystemExit(f"required_action must be a non-empty string for {scenario}")
        canonical = row.get("canonical_section")
        if not isinstance(canonical, dict):
            raise SystemExit(f"canonical_section must be a mapping for summary {scenario}")
        anchor = canonical.get("anchor")
        label = canonical.get("label")
        if not isinstance(anchor, str) or not anchor:
            raise SystemExit(f"canonical_section.anchor must be a non-empty string for {scenario}")
        if anchor.startswith("#"):
            raise SystemExit(f"canonical_section.anchor must not include '#': {anchor}")
        if not isinstance(label, str) or not label:
            raise SystemExit(f"canonical_section.label must be a non-empty string for {scenario}")


def validate_soft_labels(value: Any, context: str) -> None:
    if isinstance(value, list):
        for label in value:
            if label not in SOFT_LABEL_VALUES:
                raise SystemExit(f"Unsupported soft label {label!r} for {context}")
        return
    if value == "conditional":
        return
    if value != []:
        raise SystemExit(
            f"soft_labels must be an empty list, ['cookie_missing'], or 'conditional' for {context}"
        )


def validate_cookie_presence(value: Any, context: str, policy_path: str) -> None:
    if not isinstance(value, str):
        raise SystemExit(f"cookie_present must be a string for {context}")
    if policy_path != "hard" and not value.strip():
        raise SystemExit(f"cookie_present must be non-empty for {context}")


def validate_identifier(value: Any, context: str) -> None:
    if not isinstance(value, dict):
        raise SystemExit(f"identifier must be a mapping for {context}")
    text = value.get("text")
    if not isinstance(text, str) or not text:
        raise SystemExit(f"identifier.text must be a non-empty string for {context}")
    kind = value.get("kind")
    if kind is not None and kind not in IDENTIFIER_KINDS:
        raise SystemExit(f"Unknown identifier kind {kind!r} for {context}")


def validate_references(value: Any, context: str) -> None:
    if value is None:
        return
    if not isinstance(value, list):
        raise SystemExit(f"references must be a list for {context}")
    for anchor in value:
        if not isinstance(anchor, str) or not anchor:
            raise SystemExit(f"references must contain non-empty strings for {context}")
        if anchor.startswith("#"):
            raise SystemExit(f"references must omit leading '#': {anchor}")


def format_inline_code(value: str) -> str:
    return f"`{value}`"


def format_bool(value: bool) -> str:
    return f"`{str(value).lower()}`"


def format_soft_labels(value: Any) -> str:
    if value == "conditional":
        return "Conditional"
    if isinstance(value, list):
        if not value:
            return "—"
        return ", ".join(format_inline_code(label) for label in value)
    return "—"


def format_cookie_present(value: str) -> str:
    return value


def format_identifier(identifier: dict) -> str:
    return identifier["text"]


def format_link(link: dict) -> str:
    return f"[{link['label']}](#{link['anchor']})"


def format_cookie_policy_rows(rows: list[dict]) -> list[dict[str, str]]:
    formatted = []
    for row in rows:
        formatted.append(
            {
                "policy_path": format_inline_code(row["policy_path"]),
                "handling": row["handling"],
                "token_ok": format_bool(row["token_ok"]),
                "soft_labels": format_soft_labels(row["soft_labels"]),
                "require_challenge": format_bool(row["require_challenge"]),
                "identifier": format_identifier(row["identifier"]),
                "cookie_present": format_cookie_present(row["cookie_present"]),
                "notes": row.get("notes", ""),
            }
        )
    return formatted


def format_cookie_lifecycle_rows(rows: list[dict]) -> list[dict[str, str]]:
    formatted = []
    for row in rows:
        formatted.append(
            {
                "flow_trigger": row["flow_trigger"],
                "server_must": row["server_must"],
                "identifier": format_identifier(row["identifier"]),
                "notes": row.get("notes", ""),
            }
        )
    return formatted


def format_ncid_summary_rows(rows: list[dict]) -> list[dict[str, str]]:
    formatted = []
    for row in rows:
        formatted.append(
            {
                "scenario": row["scenario"],
                "identifier_outcome": format_identifier(row["identifier_outcome"]),
                "required_action": row["required_action"],
                "canonical_section": format_link(row["canonical_section"]),
            }
        )
    return formatted


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
        "formatter": format_cookie_lifecycle_rows,
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
        "formatter": format_cookie_policy_rows,
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
        "formatter": format_ncid_summary_rows,
    },
]


def ensure_references(data: dict, spec_text: str) -> None:
    """Basic smoke checks tying rows back to anchors and flows."""

    def check_anchor(anchor: str, context: str) -> None:
        target = anchor[1:] if anchor.startswith("#") else anchor
        patterns = [anchor, f"(#{target})", f'id="{target}"']
        if not any(token in spec_text for token in patterns):
            raise SystemExit(f"Anchor {anchor} referenced by {context} not found in spec")

    policy_rows = data.get("cookie_policy_rows", [])
    expected_policies = POLICY_PATHS
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

    anchors_required: list[tuple[str, str]] = []

    for row in policy_rows:
        policy = row["policy_path"]
        identifier_kind = row["identifier"]["kind"]
        if policy == "hard" and identifier_kind == "ncid":
            raise SystemExit("Hard policy must not mint NCIDs")
        if policy != "hard" and identifier_kind != "ncid":
            raise SystemExit(f"Policy {policy} must reference NCID outcome")
        if policy == "challenge" and not row["require_challenge"]:
            raise SystemExit("Challenge policy must require challenge")
        for anchor in row.get("references", []):
            anchors_required.append((f"#{anchor}", f"policy {policy}"))

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
        flow = row["flow_trigger"]
        for anchor in row.get("references", []):
            anchors_required.append((f"#{anchor}", f"lifecycle {flow}"))
        for anchor in extract_markdown_anchors(row.get("server_must")):
            anchors_required.append((anchor, f"server_must {flow}"))

    for row in data.get("ncid_summary_rows", []):
        scenario = row["scenario"]
        canonical_anchor = row["canonical_section"]["anchor"]
        anchors_required.append((f"#{canonical_anchor}", f"summary {scenario}"))
        for anchor in extract_markdown_anchors(row.get("required_action")):
            anchors_required.append((anchor, f"required_action {scenario}"))

    for anchor, context in anchors_required:
        check_anchor(anchor, context)


def extract_markdown_anchors(value: Any) -> Iterable[str]:
    if not isinstance(value, str):
        return []
    return [f"#{match.group(1)}" for match in ANCHOR_PATTERN.finditer(value)]


def render_table(config: dict, rows: list[dict[str, str]]) -> list[str]:
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
        raw_rows = data[config["data_key"]]
        rows = config["formatter"](raw_rows)
        rendered_block = render_table(config, rows)
        indent = " " * config["indent"]
        pointer_line = indent + POINTER_TEXT
        begin_marker = indent + f"<!-- BEGIN GENERATED: {config['name']} -->"
        end_marker = indent + f"<!-- END GENERATED: {config['name']} -->"

        if begin_marker in spec_text:
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
            end_idx += 1
        else:
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
