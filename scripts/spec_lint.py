#!/usr/bin/env python3
"""Lint helper for spec contract blocks.

This repository's specification documents rely on a structured block format
for normative "Contract" sections.  Each block must expose four bullet
headings (Inputs, Side-effects, Returns, Failure modes) so tooling can locate
and verify the contract metadata.

Historically CI invoked ``python3 scripts/spec_lint.py`` but the repository did
not include the script.  This implementation restores the entry point and
performs the structure check to keep future documents aligned with the
normative template.
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path
from typing import Iterable, List, Sequence, Tuple

HeaderVariants = Sequence[str]

REQUIRED_HEADERS: Sequence[HeaderVariants] = (
    ("- Inputs:",),
    ("- Side-effects:", "- Pure behavior:"),
    ("- Returns:",),
    ("- Failure modes:",),
)


def find_markdown_files(base_paths: Sequence[Path]) -> List[Path]:
    """Return all Markdown files under the given base paths."""

    files: List[Path] = []
    for base in base_paths:
        if base.is_file() and base.suffix.lower() == ".md":
            files.append(base)
            continue
        if base.is_dir():
            files.extend(sorted(p for p in base.rglob("*.md") if p.is_file()))
    return files


def iter_contract_blocks(lines: Sequence[str]) -> Iterable[Tuple[int, List[str]]]:
    """Yield ``(start_line_number, block_lines)`` for each contract block."""

    idx = 0
    while idx < len(lines):
        line = lines[idx]
        if line.startswith("> **Contract —"):
            block: List[str] = []
            start_line = idx + 1  # 1-indexed for reporting
            idx += 1
            while idx < len(lines) and lines[idx].startswith(">"):
                block.append(lines[idx].rstrip("\n"))
                idx += 1
            yield (start_line, block)
        else:
            idx += 1


def validate_block(block_lines: Sequence[str]) -> List[str]:
    """Return a list of error messages for the given contract block."""

    errors: List[str] = []
    header_positions = []
    for variants in REQUIRED_HEADERS:
        pos = next(
            (
                i
                for i, text in enumerate(block_lines)
                if any(
                    text.lstrip("> \t").startswith(required)
                    for required in variants
                )
            ),
            None,
        )
        if pos is None:
            expected = " or ".join(f"'{value}'" for value in variants)
            errors.append(f"missing header {expected}")
        else:
            header_positions.append(pos)

    if len(header_positions) == len(REQUIRED_HEADERS):
        if header_positions != sorted(header_positions):
            errors.append("headers appear out of order")
    return errors


def lint_files(files: Sequence[Path]) -> int:
    """Run lints across the provided files and return the error count."""

    error_count = 0
    for path in files:
        text = path.read_text(encoding="utf-8")
        lines = text.splitlines()
        for start_line, block in iter_contract_blocks(lines):
            errors = validate_block(block)
            if errors:
                error_count += len(errors)
                error_text = ", ".join(errors)
                print(f"{path}:{start_line}: {error_text}")
        ncid_errors = check_ncid_rerender_include(path, lines)
        if ncid_errors:
            error_count += len(ncid_errors)
            for message in ncid_errors:
                print(message)
        header_errors = check_cookie_header_include(path, lines)
        if header_errors:
            error_count += len(header_errors)
            for message in header_errors:
                print(message)
    return error_count


def check_ncid_rerender_include(path: Path, lines: Sequence[str]) -> List[str]:
    """Ensure sections referencing #sec-ncid-rerender include the generated snippet."""

    include_token = '--8<-- "generated/security/ncid_rerender.md"'
    errors: List[str] = []
    section_start_line = 1
    section_has_reference = False
    section_has_include = False
    in_generated_block = False
    in_code_block = False

    def flush_section() -> None:
        nonlocal section_has_reference, section_has_include, section_start_line
        if section_has_reference and not section_has_include:
            errors.append(
                f"{path}:{section_start_line}: sections referencing #sec-ncid-rerender must include {include_token}"
            )
        section_has_reference = False
        section_has_include = False

    for idx, line in enumerate(lines):
        stripped = line.strip()
        if stripped.startswith("```"):
            in_code_block = not in_code_block

        if stripped.startswith("<!-- BEGIN GENERATED:"):
            in_generated_block = True
        elif stripped.startswith("<!-- END GENERATED:"):
            in_generated_block = False
            continue

        if in_code_block:
            continue

        if stripped.startswith("#") or stripped.startswith("<a id="):
            flush_section()
            section_start_line = idx + 1
        if include_token in line:
            section_has_include = True
        if not in_generated_block and "#sec-ncid-rerender" in line:
            section_has_reference = True

    flush_section()
    return errors


def check_cookie_header_include(path: Path, lines: Sequence[str]) -> List[str]:
    """Ensure sections referencing #sec-cookie-header-actions include the generated header matrix."""

    include_token = '--8<-- "generated/security/cookie_headers.md"'
    anchor_token = "#sec-cookie-header-actions"
    errors: List[str] = []
    section_start_line = 1
    section_has_reference = False
    section_has_include = False
    in_generated_block = False
    in_code_block = False
    in_lifecycle_quickstart_block = False

    def flush_section() -> None:
        nonlocal section_has_reference, section_has_include, section_start_line
        if section_has_reference and not section_has_include:
            errors.append(
                f"{path}:{section_start_line}: sections referencing {anchor_token} must include {include_token}"
            )
        section_has_reference = False
        section_has_include = False

    for idx, line in enumerate(lines):
        stripped = line.strip()
        if stripped.startswith("```"):
            in_code_block = not in_code_block

        if stripped == "<!-- BEGIN BLOCK: lifecycle-pipeline-quickstart -->":
            in_lifecycle_quickstart_block = True
        elif stripped == "<!-- END BLOCK: lifecycle-pipeline-quickstart -->":
            in_lifecycle_quickstart_block = False

        if stripped.startswith("<!-- BEGIN GENERATED:"):
            in_generated_block = True
        elif stripped.startswith("<!-- END GENERATED:"):
            in_generated_block = False
            continue

        if in_code_block:
            continue

        if stripped.startswith("#") or stripped.startswith("<a id="):
            flush_section()
            section_start_line = idx + 1
        if include_token in line:
            section_has_include = True
        if (
            not in_generated_block
            and not in_lifecycle_quickstart_block
            and anchor_token in line
        ):
            section_has_reference = True

    flush_section()
    return errors


def main(argv: Sequence[str]) -> int:
    parser = argparse.ArgumentParser(description="Lint spec contract blocks")
    parser.add_argument(
        "paths",
        nargs="*",
        type=Path,
        default=[Path("docs")],
        help="Files or directories to lint (defaults to docs/)",
    )
    args = parser.parse_args(argv)

    files = find_markdown_files(args.paths)
    if not files:
        print("No markdown files found to lint.")
        return 0

    error_count = lint_files(files)
    return 0 if error_count == 0 else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
