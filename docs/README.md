# Documentation Guide {#sec-documentation-guide}

This guide orients contributors to the eForms documentation set so you can quickly find the right reference for any task. It also explains how to regenerate the generated spec excerpts that live alongside the canonical narrative.

## Structure overview {#sec-docs-structure}

- `docs/electronic_forms_SPEC.md` — The canonical product specification. Start here for authoritative behaviour, security, and integration details.
- `docs/roadmap.md` — High-level planning notes, upcoming milestones, and sequencing context.
- `docs/generated/` — Machine-generated excerpts that keep long tables and helper summaries in sync with the YAML sources under `tools/spec_sources/`.

	- `docs/generated/security/` collects derived security tables (such as cookie policies and NCID rerender flows) that should never be hand-edited.

## Regenerating spec excerpts {#sec-regenerating-spec-excerpts}

Files under `docs/generated/security/` are generated from the YAML inputs in `tools/spec_sources/` using `tools/generate_spec_sections.py`. To refresh them:

1. Ensure you have Python 3 available and install the `PyYAML` dependency if it is not already present: `pip install PyYAML`.
2. From the repository root, run:

	```sh
	python tools/generate_spec_sections.py
	```

The script validates the YAML schema before rewriting the generated Markdown files. Commit both the updated generated files and any source YAML edits together to avoid drift between the canonical data and the published excerpts.
