#!/usr/bin/env python3
"""Generate security spec tables from YAML data."""

import argparse
import importlib.util
import re
import sys
from pathlib import Path
from typing import Any, Iterable

if importlib.util.find_spec("yaml") is None:
    raise SystemExit(
        "PyYAML is required to generate spec tables. Install it via 'pip install PyYAML' and rerun."
    )

import yaml

SPEC_PATH = Path("docs/electronic_forms_SPEC.md")
DATA_PATH = Path("tools/spec_sources/security_data.yaml")
INCLUDE_PATH = Path("docs/generated/security/ncid_rerender.md")
COOKIE_HEADERS_INCLUDE_PATH = Path("docs/generated/security/cookie_headers.md")
POINTER_TEXT = "**Generated from `tools/spec_sources/security_data.yaml` — do not edit manually.**"
SUPPORTED_SCHEMA_VERSION = 1

POLICY_PATHS = {"hard", "soft", "off", "challenge"}
SOFT_LABEL_VALUES = {"cookie_missing"}
IDENTIFIER_KINDS = {"none", "ncid", "eid", "prime_record", "cookie_record", "submission_id"}
HEADER_ACTION_VALUES = {"positive", "deletion", "skip"}
EXPECTED_HEADER_FLOWS = {
    "GET render",
    "`/eforms/prime` request",
    "POST rerender (NCID or challenge)",
    "Challenge verification success",
    "PRG redirect (success handoff)",
}
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

    quickstart_rows = data.get("lifecycle_quickstart_rows")
    if not isinstance(quickstart_rows, list):
        raise SystemExit("lifecycle_quickstart_rows must be a list")
    for row in quickstart_rows:
        if not isinstance(row, dict):
            raise SystemExit("lifecycle_quickstart_rows entries must be mappings")
        ensure_row_id(row, "lifecycle_quickstart_rows")
        stage = row.get("stage")
        if not isinstance(stage, str) or not stage:
            raise SystemExit("lifecycle_quickstart_rows entries require a non-empty stage")
        overview = row.get("overview")
        if not isinstance(overview, str) or not overview.strip():
            raise SystemExit(f"lifecycle_quickstart_rows entry '{stage}' must include an overview")

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

    rerender_steps = data.get("ncid_rerender_steps")
    if not isinstance(rerender_steps, list) or not rerender_steps:
        raise SystemExit("ncid_rerender_steps must be a non-empty list")
    for step in rerender_steps:
        if not isinstance(step, dict):
            raise SystemExit("ncid_rerender_steps entries must be mappings")
        ensure_row_id(step, "ncid_rerender_steps")
        title = step.get("title")
        if not isinstance(title, str) or not title.strip():
            raise SystemExit("Each ncid_rerender_step must include a non-empty string title")
        action = step.get("action")
        if not isinstance(action, str) or not action.strip():
            raise SystemExit(f"ncid_rerender_step '{title}' must include a non-empty string action")

    header_rows = data.get("cookie_header_actions_rows")
    if not isinstance(header_rows, list) or not header_rows:
        raise SystemExit("cookie_header_actions_rows must be a non-empty list")
    seen_anchors: set[str] = set()
    actual_flows: set[str] = set()
    for row in header_rows:
        if not isinstance(row, dict):
            raise SystemExit("cookie_header_actions_rows entries must be mappings")
        ensure_row_id(row, "cookie_header_actions_rows")
        flow_trigger = row.get("flow_trigger")
        if not isinstance(flow_trigger, str) or not flow_trigger:
            raise SystemExit("cookie_header_actions_rows entries require a non-empty flow_trigger")
        actual_flows.add(flow_trigger)
        header_action = row.get("header_action")
        if header_action not in HEADER_ACTION_VALUES:
            raise SystemExit(
                "header_action must be one of {'positive', 'deletion', 'skip'} for cookie_header_actions_rows"
            )
        invariants = row.get("invariants")
        if not isinstance(invariants, str) or not invariants.strip():
            raise SystemExit(
                f"cookie_header_actions_rows entry '{flow_trigger}' must include non-empty invariants"
            )
        anchor = row.get("anchor")
        if not isinstance(anchor, str) or not anchor:
            raise SystemExit(
                f"cookie_header_actions_rows entry '{flow_trigger}' must include a non-empty anchor"
            )
        if anchor.startswith("#"):
            raise SystemExit(
                f"cookie_header_actions_rows entry '{flow_trigger}' anchor must not include '#': {anchor}"
            )
        if not anchor.startswith("sec-"):
            raise SystemExit(
                f"cookie_header_actions_rows entry '{flow_trigger}' anchor must start with 'sec-': {anchor}"
            )
        if anchor in seen_anchors:
            raise SystemExit(f"Duplicate cookie header anchor detected: {anchor}")
        seen_anchors.add(anchor)
        validate_references(row.get("references"), f"cookie_header_action {flow_trigger}")

    if actual_flows != EXPECTED_HEADER_FLOWS:
        missing = EXPECTED_HEADER_FLOWS - actual_flows
        extra = actual_flows - EXPECTED_HEADER_FLOWS
        details = []
        if missing:
            details.append(f"missing {sorted(missing)}")
        if extra:
            details.append(f"unexpected {sorted(extra)}")
        raise SystemExit(
            "cookie_header_actions_rows flows mismatch: " + ", ".join(details)
        )

    slot_summary_rows = data.get("slot_handling_summary_rows")
    if slot_summary_rows is None:
        raise SystemExit("slot_handling_summary_rows must be provided")
    validate_bullet_rows(
        slot_summary_rows,
        "slot_handling_summary_rows",
        require_id=True,
    )

    prime_guidance_rows = data.get("prime_set_cookie_guidance_rows")
    if prime_guidance_rows is None:
        raise SystemExit("prime_set_cookie_guidance_rows must be provided")
    validate_bullet_rows(
        prime_guidance_rows,
        "prime_set_cookie_guidance_rows",
        require_id=True,
    )


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


def validate_bullet_rows(rows: Any, context: str, *, require_id: bool) -> None:
    if not isinstance(rows, list):
        raise SystemExit(f"{context} must be a list")
    for row in rows:
        if not isinstance(row, dict):
            raise SystemExit(f"{context} entries must be mappings")
        row_id = row.get("id")
        if require_id:
            if not isinstance(row_id, str) or not row_id:
                raise SystemExit(f"{context} entries must include a non-empty string id")
        text = row.get("text")
        if not isinstance(text, str) or not text:
            raise SystemExit(f"{context} entries must include non-empty text")
        children = row.get("children")
        if children is not None:
            validate_bullet_rows(children, f"{context} child of {row.get('id', '<item>')}", require_id=False)


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


def format_lifecycle_quickstart_rows(rows: list[dict]) -> list[dict[str, str]]:
    formatted = []
    for row in rows:
        formatted.append(
            {
                "stage": row["stage"],
                "overview": row["overview"],
            }
        )
    return formatted


def format_cookie_header_actions_rows(rows: list[dict]) -> list[dict[str, str]]:
    formatted = []
    for row in rows:
        anchor = row["anchor"]
        flow_trigger = row["flow_trigger"]
        formatted.append(
            {
                "flow_trigger": f"<a id=\"{anchor}\"></a>{flow_trigger}",
                "header_action": format_inline_code(row["header_action"]),
                "invariants": row["invariants"],
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


def render_ncid_rerender_steps(steps: list[dict]) -> str:
    lines = [
        POINTER_TEXT,
        "<!-- BEGIN GENERATED: ncid-rerender-steps -->",
    ]
    for step in steps:
        title = step["title"].strip()
        action = step["action"].strip()
        lines.append(f"- **{title}** — {action}")
    lines.append("<!-- END GENERATED: ncid-rerender-steps -->")
    return "\n".join(lines) + "\n"


def get_indent_string(config: dict) -> str:
    indent_value = config.get("indent", 0)
    if isinstance(indent_value, int):
        if indent_value < 0:
            raise SystemExit("indent must be non-negative")
        return " " * indent_value
    if isinstance(indent_value, str):
        return indent_value
    raise SystemExit("indent must be an integer or string")


def render_bullet_rows(
    rows: list[dict],
    *,
    base_indent: str,
    indent_unit: str = "\t",
) -> list[str]:
    lines: list[str] = []

    def emit(node: dict, depth: int) -> None:
        indent = base_indent + indent_unit * depth
        lines.append(f"{indent}- {node['text']}")
        children = node.get("children")
        if isinstance(children, list):
            for child in children:
                emit(child, depth + 1)

    for node in rows:
        emit(node, 0)
    return lines


def render_bullet_block(
    name: str,
    rows: list[dict],
    *,
    base_indent: str,
    indent_unit: str = "\t",
) -> list[str]:
    lines = [
        base_indent + POINTER_TEXT,
        base_indent + f"<!-- BEGIN GENERATED: {name} -->",
    ]
    lines.extend(render_bullet_rows(rows, base_indent=base_indent, indent_unit=indent_unit))
    lines.append(base_indent + f"<!-- END GENERATED: {name} -->")
    return lines


def render_table_block(config: dict, data: dict) -> list[str]:
    raw_rows = data[config["data_key"]]
    rows = config["formatter"](raw_rows)
    return render_table(config, rows)


def render_bullet_block_config(config: dict, data: dict) -> list[str]:
    rows = data[config["data_key"]]
    base_indent = get_indent_string(config)
    indent_unit = config.get("indent_unit", "\t")
    return render_bullet_block(
        config["name"],
        rows,
        base_indent=base_indent,
        indent_unit=indent_unit,
    )


GENERATED_CONFIGS = [
    {
        "name": "lifecycle-quickstart",
        "data_key": "lifecycle_quickstart_rows",
        "indent": 0,
        "header_token": "| Stage | Overview |",
        "header": "| Stage | Overview |",
        "separator": "|-------|----------|",
        "columns": [
            ("stage", "Stage"),
            ("overview", "Overview"),
        ],
        "formatter": format_lifecycle_quickstart_rows,
        "render": render_table_block,
    },
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
        "render": render_table_block,
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
        "render": render_table_block,
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
        "render": render_table_block,
    },
    {
        "name": "cookie-header-actions",
        "data_key": "cookie_header_actions_rows",
        "indent": 0,
        "header_token": "| Flow trigger | Header action | Invariants |",
        "header": "| Flow trigger | Header action | Invariants |",
        "separator": "|--------------|---------------|------------|",
        "columns": [
            ("flow_trigger", "Flow trigger"),
            ("header_action", "Header action"),
            ("invariants", "Invariants"),
        ],
        "formatter": format_cookie_header_actions_rows,
        "path": COOKIE_HEADERS_INCLUDE_PATH,
        "render": render_table_block,
    },
    {
        "name": "slot-handling-summary",
        "data_key": "slot_handling_summary_rows",
        "indent": "",
        "render": render_bullet_block_config,
        "insertion_token": "**Slot handling:**",
        "leading_blank_line": True,
    },
    {
        "name": "prime-set-cookie-guidance",
        "data_key": "prime_set_cookie_guidance_rows",
        "indent": "\t\t\t\t",
        "render": render_bullet_block_config,
        "insertion_token": "- Prime endpoint semantics (`/eforms/prime`):",
        "leading_blank_line": True,
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

    for row in data.get("cookie_header_actions_rows", []):
        flow = row["flow_trigger"]
        anchor = row.get("anchor")
        if anchor:
            anchors_required.append((f"#{anchor}", f"cookie_header_anchor {flow}"))
        for anchor in row.get("references", []):
            anchors_required.append((f"#{anchor}", f"cookie_header_action {flow}"))
        for anchor in extract_markdown_anchors(row.get("invariants")):
            anchors_required.append((anchor, f"cookie_header_invariants {flow}"))

    for row in data.get("lifecycle_quickstart_rows", []):
        stage = row.get("stage", "")
        for anchor in extract_markdown_anchors(row.get("overview")):
            anchors_required.append((anchor, f"quickstart {stage}"))

    collect_bullet_anchors(
        data.get("slot_handling_summary_rows", []),
        "slot_handling_summary",
        anchors_required,
    )
    collect_bullet_anchors(
        data.get("prime_set_cookie_guidance_rows", []),
        "prime_set_cookie_guidance",
        anchors_required,
    )

    for anchor, context in anchors_required:
        check_anchor(anchor, context)


def extract_markdown_anchors(value: Any) -> Iterable[str]:
    if not isinstance(value, str):
        return []
    return [f"#{match.group(1)}" for match in ANCHOR_PATTERN.finditer(value)]


def collect_bullet_anchors(rows: list[dict], context: str, bucket: list[tuple[str, str]]) -> None:
    for row in rows or []:
        text = row.get("text", "")
        row_context = f"{context} {row.get('id', text)}"
        for anchor in extract_markdown_anchors(text):
            bucket.append((anchor, row_context))
        children = row.get("children")
        if isinstance(children, list):
            collect_bullet_anchors(children, row_context, bucket)


def render_table(config: dict, rows: list[dict[str, str]]) -> list[str]:
    lines = [config["header"], config["separator"]]
    column_order = [field for field, _ in config["columns"]]
    for row in rows:
        cells = [row[field] for field in column_order]
        line = "| " + " | ".join(cells) + " |"
        lines.append(line)
    indent = get_indent_string(config)
    prefixed = [indent + line if line else line for line in lines]
    block = [indent + POINTER_TEXT, indent + f"<!-- BEGIN GENERATED: {config['name']} -->"]
    block.extend(prefixed)
    block.append(indent + f"<!-- END GENERATED: {config['name']} -->")
    return block


def integrate_generated_content(data: dict, *, check: bool) -> bool:
    configs_by_path: dict[Path, list[dict]] = {}
    for config in GENERATED_CONFIGS:
        target_path = Path(config.get("path", SPEC_PATH))
        configs_by_path.setdefault(target_path, []).append(config)

    file_texts: dict[Path, str] = {}
    for path in configs_by_path:
        if path.exists():
            file_texts[path] = path.read_text(encoding="utf-8")
        else:
            file_texts[path] = ""

    if check:
        combined_text = "\n".join(file_texts.values())
        ensure_references(data, combined_text)

    ok = True
    new_texts: dict[Path, str] = {}
    for path, configs in configs_by_path.items():
        original_text = file_texts[path]
        lines = original_text.splitlines()
        updated = lines[:]

        def ensure_leading_blank_line(lines: list[str], pointer_idx: int) -> None:
            if pointer_idx < 0 or pointer_idx > len(lines):
                return
            idx = pointer_idx
            while idx > 1 and lines[idx - 1] == "" and lines[idx - 2] == "":
                del lines[idx - 1]
                idx -= 1
            if idx == 0:
                lines.insert(0, "")
            elif lines[idx - 1] != "":
                lines.insert(idx, "")

        for config in configs:
            rendered_block = config["render"](config, data)
            indent = get_indent_string(config)
            pointer_line = indent + POINTER_TEXT
            begin_marker = indent + f"<!-- BEGIN GENERATED: {config['name']} -->"
            end_marker = indent + f"<!-- END GENERATED: {config['name']} -->"
            leading_blank = bool(config.get("leading_blank_line"))

            if begin_marker in original_text:
                start_idx = None
                end_idx = None
                for idx, line in enumerate(updated):
                    if idx + 1 >= len(updated):
                        continue
                    if line == pointer_line and updated[idx + 1] == begin_marker:
                        start_idx = idx
                        break
                if start_idx is None:
                    trimmed_pointer = POINTER_TEXT.strip()
                    trimmed_begin = begin_marker.strip()
                    for idx, line in enumerate(updated):
                        if idx + 1 >= len(updated):
                            continue
                        if line.strip() == trimmed_pointer and updated[idx + 1].strip() == trimmed_begin:
                            start_idx = idx
                            break
                if start_idx is None:
                    raise SystemExit(f"Pointer line for {config['name']} not found in {path}")
                pointer = POINTER_TEXT.strip()
                legacy_begin = f"<!-- BEGIN GENERATED: {config['name']} -->"
                while start_idx > 0 and updated[start_idx - 1].strip() in {
                    pointer,
                    legacy_begin,
                }:
                    start_idx -= 1
                trimmed_end = end_marker.strip()
                for idx in range(start_idx, len(updated)):
                    if updated[idx] == end_marker or updated[idx].strip() == trimmed_end:
                        end_idx = idx
                        break
                if end_idx is None:
                    raise SystemExit(f"End marker for {config['name']} not found in {path}")
                end_idx += 1
                legacy_end = f"<!-- END GENERATED: {config['name']} -->"
                while end_idx < len(updated) and updated[end_idx].strip() in {
                    pointer,
                    legacy_begin,
                    legacy_end,
                }:
                    end_idx += 1
                updated[start_idx:end_idx] = rendered_block
                if leading_blank:
                    ensure_leading_blank_line(updated, start_idx)
            else:
                header_token = config.get("header_token")
                insertion_token = config.get("insertion_token")
                if header_token is not None:
                    start_idx = None
                    needle = header_token.strip()
                    for idx, line in enumerate(updated):
                        if line.strip() == needle:
                            start_idx = idx
                            break
                    if start_idx is None:
                        raise SystemExit(f"Table header for {config['name']} not found in {path}")
                    # Capture any legacy pointer lines or markers that may precede the
                    # header so we replace the entire generated block on regeneration.
                    pointer = POINTER_TEXT.strip()
                    legacy_begin = f"<!-- BEGIN GENERATED: {config['name']} -->"
                    while start_idx > 0 and updated[start_idx - 1].strip() in {
                        pointer,
                        legacy_begin,
                    }:
                        start_idx -= 1

                    end_idx = start_idx
                    while end_idx < len(updated) and updated[end_idx].strip().startswith("|"):
                        end_idx += 1
                    legacy_end = f"<!-- END GENERATED: {config['name']} -->"
                    while end_idx < len(updated) and updated[end_idx].strip() in {
                        pointer,
                        legacy_begin,
                        legacy_end,
                    }:
                        end_idx += 1
                    updated[start_idx:end_idx] = rendered_block
                    if leading_blank:
                        ensure_leading_blank_line(updated, start_idx)
                elif insertion_token is not None:
                    insertion_idx = None
                    needle = insertion_token.strip()
                    for idx, line in enumerate(updated):
                        if line.strip() == needle:
                            insertion_idx = idx + 1
                            break
                    if insertion_idx is None:
                        raise SystemExit(
                            f"Insertion token for {config['name']} not found in {path}"
                        )
                    updated[insertion_idx:insertion_idx] = rendered_block
                    if leading_blank:
                        ensure_leading_blank_line(updated, insertion_idx)
                else:
                    raise SystemExit(
                        f"Begin marker for {config['name']} not found in {path}"
                    )

        new_text = "\n".join(updated)
        if updated:
            new_text += "\n"

        new_texts[path] = new_text
        if check:
            if new_text != original_text:
                sys.stderr.write(
                    f"{path.as_posix()} is stale. Run tools/generate_spec_sections.py to update.\n"
                )
                ok = False

    if not check:
        combined_new_text = "\n".join(new_texts.values())
        ensure_references(data, combined_new_text)
        for path, new_text in new_texts.items():
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(new_text, encoding="utf-8")

    return ok


def write_ncid_rerender_include(data: dict, *, check: bool) -> bool:
    content = render_ncid_rerender_steps(data["ncid_rerender_steps"])
    if check:
        if not INCLUDE_PATH.exists() or INCLUDE_PATH.read_text(encoding="utf-8") != content:
            sys.stderr.write(
                "NCID rerender include is stale. Run tools/generate_spec_sections.py to update.\n"
            )
            return False
        return True
    INCLUDE_PATH.parent.mkdir(parents=True, exist_ok=True)
    INCLUDE_PATH.write_text(content, encoding="utf-8")
    return True


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate security spec matrices")
    parser.add_argument("--check", action="store_true", help="Verify spec matches generated tables")
    args = parser.parse_args()

    data = load_data()
    include_ok = write_ncid_rerender_include(data, check=args.check)
    content_ok = integrate_generated_content(data, check=args.check)
    ok = include_ok and content_ok
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
