#!/usr/bin/env python3
"""
JcbInOut - Phase 2 validation gate.

Checks a generated LionWeb chunk against:
  1. the official serialization JSON schema
  2. structural integrity (unique ids, resolvable children, parent agreement)
  3. LionCore conformance (every classifier/feature metapointer is a real M3 key)

Usage: validate-lionweb.py <chunk.json> [vendor-lionweb-dir]
"""

import json
import sys
from collections import Counter
from pathlib import Path

chunk_path = Path(sys.argv[1] if len(sys.argv) > 1 else "jcb-language.lionweb.json")
vendor = Path(sys.argv[2] if len(sys.argv) > 2 else "vendor-lionweb")

chunk = json.loads(chunk_path.read_text(encoding="utf-8"))
failures: list[str] = []
warnings: list[str] = []


def fail(msg: str) -> None:
    failures.append(msg)


# --- 1. official schema ------------------------------------------------------

try:
    import jsonschema

    schema = json.loads((vendor / "serialization.schema.json").read_text(encoding="utf-8"))
    validator = jsonschema.Draft202012Validator(schema)
    errors = sorted(validator.iter_errors(chunk), key=lambda e: list(e.path))

    for e in errors[:20]:
        fail(f"schema: {'/'.join(str(x) for x in e.path)}: {e.message}")

    if not errors:
        print("  [ok] validates against the official LionWeb serialization schema")
    else:
        print(f"  [FAIL] {len(errors)} schema violation(s)")
except ImportError:
    warnings.append("jsonschema not installed - schema validation skipped")

# --- 2. structural integrity -------------------------------------------------

nodes = chunk["nodes"]
ids = [n["id"] for n in nodes]
dupes = [i for i, c in Counter(ids).items() if c > 1]

if dupes:
    fail(f"duplicate node ids: {dupes[:5]}")
else:
    print(f"  [ok] {len(ids)} node ids unique")

by_id = {n["id"]: n for n in nodes}

# children must exist, and each child's parent must point back
child_of: dict[str, str] = {}

for n in nodes:
    # An annotation instance's parent is the annotated node; it is listed in
    # that node's "annotations", not in a containment.
    for ann in n.get("annotations", []):
        if ann not in by_id:
            fail(f"node {n['id']} annotated by unknown node {ann}")
        else:
            child_of[ann] = n["id"]

    for c in n.get("containments", []):
        for ch in c["children"]:
            if ch not in by_id:
                fail(f"node {n['id']} contains unknown child {ch}")
            else:
                if ch in child_of:
                    fail(f"node {ch} is contained twice ({child_of[ch]}, {n['id']})")
                child_of[ch] = n["id"]

bad_parent = 0

for n in nodes:
    expected = child_of.get(n["id"])
    if n.get("parent") != expected:
        bad_parent += 1
        if bad_parent <= 5:
            fail(f"node {n['id']}: parent={n.get('parent')!r} but contained by {expected!r}")

if not bad_parent:
    print(f"  [ok] parent/containment agreement across {len(nodes)} nodes")

roots = [n["id"] for n in nodes if n.get("parent") is None]

if len(roots) != 1:
    fail(f"expected exactly one root node, found {len(roots)}: {roots[:5]}")
else:
    print(f"  [ok] single root: {roots[0]}")

# --- 3. LionCore conformance -------------------------------------------------

m3 = json.loads((vendor / "lioncore.json").read_text(encoding="utf-8"))
builtins = json.loads((vendor / "builtins.json").read_text(encoding="utf-8"))


def keyed(doc):
    out = {}
    for n in doc["nodes"]:
        for p in n.get("properties", []):
            if p["property"]["key"] == "IKeyed-key":
                out[p["value"]] = n["classifier"]["key"]
    return out


m3_keys = keyed(m3)
builtin_ids = {n["id"] for n in builtins["nodes"]} | {n["id"] for n in m3["nodes"]}

declared_langs = {l["key"] for l in chunk.get("languages", [])}
unknown_mp = Counter()

for n in nodes:
    mps = [("classifier", n["classifier"])]
    mps += [("property", p["property"]) for p in n.get("properties", [])]
    mps += [("containment", c["containment"]) for c in n.get("containments", [])]
    mps += [("reference", r["reference"]) for r in n.get("references", [])]

    for role, mp in mps:
        if mp["language"] == "LionCore-M3":
            if mp["key"] not in m3_keys:
                unknown_mp[f"{role}:{mp['key']}"] += 1
        elif mp["language"] == "LionCore-builtins":
            if mp["key"] not in {"LionCore-builtins-INamed-name"}:
                unknown_mp[f"{role}:{mp['key']}"] += 1
        elif mp["language"] in declared_langs:
            pass   # the chunk declares this language (e.g. its own annotations)
        else:
            unknown_mp[f"{role}:{mp['language']}/{mp['key']}"] += 1

if unknown_mp:
    for k, c in unknown_mp.most_common(10):
        fail(f"unknown metapointer {k} (x{c})")
else:
    print("  [ok] every metapointer resolves to a real LionCore/builtins key")

# external reference targets must be builtins or in-chunk
ext_bad = Counter()

for n in nodes:
    for r in n.get("references", []):
        for t in r["targets"]:
            ref = t.get("reference")
            if ref is None:
                continue
            if ref not in by_id and ref not in builtin_ids:
                ext_bad[ref] += 1

if ext_bad:
    for k, c in ext_bad.most_common(10):
        fail(f"reference target not resolvable: {k} (x{c})")
else:
    print("  [ok] every reference target resolves in-chunk or to builtins")

# --- report ------------------------------------------------------------------

print()
counts = Counter(n["classifier"]["key"] for n in nodes)
print("  node census:", dict(sorted(counts.items())))

for w in warnings:
    print(f"  [warn] {w}")

if failures:
    print(f"\nFAIL - {len(failures)} problem(s):")
    for f in failures[:25]:
        print(f"  - {f}")
    sys.exit(1)

print("\nPASS - chunk is a valid LionWeb language serialisation.")
