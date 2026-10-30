# Package zip layout (`GET /v1/packages/{build_id}`)

Canonical for how a package zip is assembled and how it's installed. `manifest.json` is the only file interface between the two sides — neither invents a field here without a contract change (bump `contract/v1/VERSION` and record it in the contract's changelog).

## Zip contents

```
{build_id}.zip
├── manifest.json
├── {text_domain}-{locale}.po
├── {text_domain}-{locale}.mo
├── {text_domain}-{locale}-{md5}.json      (present only if the component ships JS/script translations; {md5} = md5(relative path of the associated script handle), matching WP core's load_script_textdomain() convention)
├── {extra_domain}-{locale}.po|.mo|-{md5}.json   (one of the same triplet per entry in manifest.json's extra_domains[], present only when that extra domain has any of these files)
└── default/
    └── {slug}-{locale}.po|.mo             (present only if this component's translation run produced any default-domain supplement)
```

- `.po` ships alongside `.mo` in the zip so the plugin can keep a source-of-truth copy locally; only `.mo`/derived compiled formats ever land in the live-serving directories, never `.po` (no remote code, only translation data formats are ever fetched or executed).
- `default/` mirrors the same `.po`/`.mo` pairing, one file per format, for the component's contribution to the per-locale `default` domain supplement.
- **`.po` never carries internal comments**: each `.po` is an external copy the service produces at import time — any comment line starting with `#.` or `#` (one space) followed immediately by `babilim` and then `:` or `-` is removed (this family covers, among others, `#. babilim: source=...` — the pipeline's per-string source marker, `# babilim: kept-with-notes; ...` — translator/model-review notes, and `#. babilim-concat: ...` — concatenation-context notes; any future same-family prefix the pipeline adds is covered the same way, not just these three). Every other comment kind (`#:` references, `#,` flags, `#|` previous-msgid, etc.) is kept as-is. The pipeline's original `.po` (with these internal comments still in it) is kept internally by the service and never reaches this zip or any public endpoint.
- **Per-download header injection / traceability id (1.3.0)**: at the moment a package is actually assembled (every `GET /v1/packages/{build_id}` call, not at import time), the service writes two fields into every `.mo`/`.po`/`.json`'s header (the `""` entry) — `X-Babilim-Site` (this download's site public id, `st_...`) and `X-Babilim-Build` (`server_build_id`, `bd_...`, overwriting whatever value the pipeline's own `.po`/`.mo` may already carry under that same key, a different id space — see `build_id` below). **Only the header changes — a `.mo` is identical to the pipeline's original outside its header, and a `.po` is identical to the comment-stripped external copy described above outside its header**: every other entry — every msgid/msgstr, in the same order — is byte-for-byte (`.po`) or logically (`.mo`, which must be fully re-serialized to relocate its offset table once the header entry's length changes; `.json`) identical. The stored original in Wasabi/storage is never touched or duplicated per site — this transform happens only on the copy going into THIS one response's zip. Not encrypted (GPL derivative work, wp.org review, performance) — this is a traceability marker, not a protection mechanism.

## `manifest.json`

Two layers, merged into one flat object (`contract/v1/schemas/package-manifest.schema.json`, `additionalProperties: false`):

1. **The pipeline's own run manifest**, copied as-is except its internal grading field is stripped (nothing about how a translation was graded is ever shipped to a site) AND every entry's `sha256`/`size` in `files`/`default_domain_files` is recomputed against the bytes **actually shipped in this response** — the external, comment-stripped `.po` copy (see the "`.po` never carries internal comments" bullet above) further transformed by this request's own header injection (see "Per-download header injection" above), never the pipeline's own working copy or the stored original. Because the header injection is per-request (it embeds THIS download's site id), these two digests differ from one download of the same build to the next when a different site requests it — they are not a stable, cacheable property of the build itself the way every other field in this manifest is. Remaining fields: `build_id` (the pipeline's own run id — a different id space from the service's `server_build_id`), `entries`, `files`, `default_domain_entries`, `default_domain_files`, `locale`, `status` (always `"published"` — the service only ever imports published runs).
2. **Fields the service appends** when it builds the zip: `kind`, `slug`, `version`, `text_domain`, `extra_domains[]`, `server_build_id` (the `bd_...` id used everywhere else in this contract), `build_no`, `issued_at`, `contract` (the contract version this package was built against).

`files` and `default_domain_files` are both `{filename: {sha256, size}}` maps (`package-manifest.schema.json`'s `$defs.file_digest`), and both are keyed by **bare filename, not a path**:
- `files` keys are relative to the zip root and cover every file the zip ships for the component's own text_domain **and** each of its `extra_domains[]` — the main `.po`/`.mo` pair, and the `-{md5}.json` script-translation file when the component has one. A component with no `extra_domains` and no script translations only has the two main-domain entries; one with both has more.
- `default_domain_files` keys are relative to the zip's `default/` directory (not the zip root) — e.g. the key `{slug}-{locale}.mo` means `default/{slug}-{locale}.mo`, not `{slug}-{locale}.mo` at the zip root. This map is absent (or empty) when the run produced no default-domain supplement.

See `contract/v1/examples/packages.manifest.json` for a worked instance and `contract/v1/schemas/package-manifest.schema.json` for the schema `tools/validate_examples.py` checks it against.

**No quality fields of any kind appear here** — not a grading field, not a grade, not evidence. This is the same zero-quality-fields-on-the-wire rule the rest of the contract holds to; `package-manifest.schema.json`'s `additionalProperties: false` is what makes "add a quality field back" a red, not a green, contract-check.
