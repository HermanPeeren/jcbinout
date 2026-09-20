#!/usr/bin/env python3
"""
JcbInOut - Phase 2 design experiment.

JCB's Table.php column GUIDs are the portable identities of the *field
definitions* that generated those columns. Where one GUID appears on several
entities, JCB is asserting that those columns are one reusable definition used
at several use-sites.

This script builds variants of the baseline language so that assertion can be
compared as real output rather than argued in the abstract:

  base        the current generator output (entity-qualified keys, side-car only)
  dedupe      merge enums and row concepts that share a field-definition GUID
  annotate    dedupe + a JcbFieldDefinition annotation carrying the guid
  interfaces  dedupe + shared definitions hoisted into Interfaces

Variants are transformations of the validated baseline chunk, so the production
PHP generator stays untouched until a winner is chosen.

Usage: prototype-encodings.py [baseline.json] [outdir]
"""

import json
import sys
from collections import defaultdict
from copy import deepcopy
from pathlib import Path

LW = "2024.1"
M3 = "LionCore-M3"
BUILTINS = "LionCore-builtins"
LANG = "jcb"

M3_FEATURE_ID = "-id-Feature-2024-1"

root = Path(__file__).resolve().parent.parent
base_path = Path(sys.argv[1]) if len(sys.argv) > 1 else root / "jcb-language.lionweb.json"
outdir = Path(sys.argv[2]) if len(sys.argv) > 2 else root / "prototypes"
outdir.mkdir(exist_ok=True)

base = json.loads(base_path.read_text(encoding="utf-8"))
meta = json.loads((root / "jcb-metamodel.json").read_text(encoding="utf-8"))
fkeys = json.loads((root / "jcb-feature-keys.json").read_text(encoding="utf-8"))

shared = fkeys["sharedGuids"]
entities = meta["entities"]


# --- helpers ----------------------------------------------------------------

def mp(lang, key):
    return {"language": lang, "version": LW, "key": key}


def prop_of(node, key):
    for p in node.get("properties", []):
        if p["property"]["key"] == key:
            return p["value"]
    return None


def set_prop(node, key, value, lang=M3):
    for p in node.get("properties", []):
        if p["property"]["key"] == key:
            p["value"] = value
            return
    node.setdefault("properties", []).append({"property": mp(lang, key), "value": value})


def node_name(n):
    return prop_of(n, "LionCore-builtins-INamed-name")


def node_key(n):
    return prop_of(n, "IKeyed-key")


def index(chunk):
    return {n["id"]: n for n in chunk["nodes"]}


def children_of(node, key):
    for c in node.get("containments", []):
        if c["containment"]["key"] == key:
            return c["children"]
    return []


def set_children(node, key, ids):
    for c in node.get("containments", []):
        if c["containment"]["key"] == key:
            c["children"] = ids
            return
    node.setdefault("containments", []).append(
        {"containment": mp(M3, key), "children": ids})


def ref_target(node, key):
    for r in node.get("references", []):
        if r["reference"]["key"] == key:
            return r["targets"][0]["reference"] if r["targets"] else None
    return None


def set_ref(node, key, target_id, resolve):
    for r in node.get("references", []):
        if r["reference"]["key"] == key:
            r["targets"] = [{"resolveInfo": resolve, "reference": target_id}]
            return
    node.setdefault("references", []).append(
        {"reference": mp(M3, key),
         "targets": [{"resolveInfo": resolve, "reference": target_id}]})


def pascal(s):
    return "".join(w.capitalize() for w in s.replace("-", "_").split("_"))


def new_node(nid, classifier_key, name, key, parent, lang=M3):
    n = {
        "id": nid,
        "classifier": mp(lang, classifier_key),
        "properties": [],
        "containments": [],
        "references": [],
        "annotations": [],
        "parent": parent,
    }
    if name is not None:
        n["properties"].append(
            {"property": mp(BUILTINS, "LionCore-builtins-INamed-name"), "value": name})
    if key is not None:
        n["properties"].append({"property": mp(M3, "IKeyed-key"), "value": key})
    return n


# --- shared-definition facts ------------------------------------------------

def guid_of(entity, prop):
    return entities[entity]["properties"][prop].get("guid")


# guid -> list of (entity, property)
guid_sites = defaultdict(list)
for key, info in fkeys["featureKeys"].items():
    if info.get("guid"):
        guid_sites[info["guid"]].append((info["entity"], info["property"]))

# feature node id -> (entity, property, guid)
feature_site = {}
for ename, e in entities.items():
    if not e.get("portable"):
        continue
    for pname, p in (e.get("properties") or {}).items():
        if not p.get("portable"):
            continue
        fid = f"jcb-{ename}-{pname}".replace("_", "_")
        feature_site[fid] = (ename, pname, p.get("guid"))


# ---------------------------------------------------------------------------
# Variant 1: dedupe enums and row concepts that share a field-definition GUID
# ---------------------------------------------------------------------------

def variant_dedupe(chunk):
    c = deepcopy(chunk)
    by_id = index(c)
    lang_node = by_id["jcb-language"]

    # Which enums / row concepts belong to which field-definition guid?
    enum_by_guid = defaultdict(set)
    row_by_guid = defaultdict(set)

    for ename, e in entities.items():
        if not e.get("portable"):
            continue
        for pname, p in (e.get("properties") or {}).items():
            if not p.get("portable"):
                continue
            g = p.get("guid")
            if not g:
                continue
            if p.get("enumeration"):
                enum_by_guid[g].add(p["enumeration"])
            rc = (p.get("subform") or {}).get("rowConcept")
            if rc:
                row_by_guid[g].add(rc)

    # canonical name per guid, and a rename map for the duplicates
    enum_alias, row_alias = {}, {}
    merged_enums = merged_rows = 0

    for g, names in enum_by_guid.items():
        if len(names) < 2:
            continue
        canon_short = pascal(guid_sites[g][0][1])
        canon = sorted(names)[0]
        for n in names:
            enum_alias[n] = canon
        merged_enums += len(names) - 1

    for g, names in row_by_guid.items():
        if len(names) < 2:
            continue
        canon = sorted(names)[0]
        for n in names:
            row_alias[n] = canon
        merged_rows += len(names) - 1

    # map element name -> node id
    name_to_id = {}
    for n in c["nodes"]:
        if n["classifier"]["key"] in ("Enumeration", "Concept"):
            name_to_id[node_name(n)] = n["id"]

    drop = set()
    for dup, canon in list(enum_alias.items()) + list(row_alias.items()):
        if dup != canon and dup in name_to_id:
            drop.add(name_to_id[dup])

    # repoint every feature that typed against a dropped node
    id_alias = {}
    for dup, canon in list(enum_alias.items()) + list(row_alias.items()):
        if dup != canon and dup in name_to_id and canon in name_to_id:
            id_alias[name_to_id[dup]] = name_to_id[canon]

    for n in c["nodes"]:
        for r in n.get("references", []):
            for t in r["targets"]:
                tgt = t.get("reference")
                if tgt in id_alias:
                    canon_id = id_alias[tgt]
                    t["reference"] = canon_id
                    t["resolveInfo"] = node_name(by_id[canon_id])

    # drop the duplicate nodes and their children (literals / row features)
    to_remove = set(drop)
    for d in drop:
        stack = [d]
        while stack:
            cur = stack.pop()
            for cont in by_id[cur].get("containments", []):
                for ch in cont["children"]:
                    to_remove.add(ch)
                    stack.append(ch)

    c["nodes"] = [n for n in c["nodes"] if n["id"] not in to_remove]
    set_children(lang_node, "Language-entities",
                 [i for i in children_of(lang_node, "Language-entities")
                  if i not in to_remove])

    c["_stats"] = {"mergedEnums": merged_enums, "mergedRowConcepts": merged_rows}
    return c


# ---------------------------------------------------------------------------
# Variant 2: annotation carrying the field-definition guid
# ---------------------------------------------------------------------------

def variant_annotate(chunk):
    c = deepcopy(chunk)
    by_id = index(c)
    lang_node = by_id["jcb-language"]

    ann_id = "jcb-ann-JcbFieldDefinition"
    ann = new_node(ann_id, "Annotation", "JcbFieldDefinition",
                   "JcbFieldDefinition", "jcb-language")
    # annotates every LionCore Feature (Property / Containment / Reference)
    set_ref(ann, "Annotation-annotates", M3_FEATURE_ID, "Feature")

    guid_prop_id = ann_id + "-guid"
    guid_prop = new_node(guid_prop_id, "Property", "guid",
                         "JcbFieldDefinition-guid", ann_id)
    set_prop(guid_prop, "Feature-optional", "false")
    set_ref(guid_prop, "Property-type",
            f"{BUILTINS}-String-{LW.replace('.', '-')}", "String")
    set_children(ann, "Classifier-features", [guid_prop_id])

    c["nodes"].extend([ann, guid_prop])
    set_children(lang_node, "Language-entities",
                 children_of(lang_node, "Language-entities") + [ann_id])

    # one annotation instance per feature that has a guid
    instances = 0
    for n in c["nodes"]:
        if n["classifier"]["key"] not in ("Property", "Containment", "Reference"):
            continue
        if n["classifier"]["language"] != M3:
            continue
        site = feature_site.get(n["id"])
        if not site or not site[2]:
            continue
        inst_id = n["id"] + "-def"
        inst = {
            "id": inst_id,
            "classifier": mp(LANG, "JcbFieldDefinition"),
            "properties": [{"property": mp(LANG, "JcbFieldDefinition-guid"),
                            "value": site[2]}],
            "containments": [],
            "references": [],
            "annotations": [],
            "parent": n["id"],
        }
        n.setdefault("annotations", []).append(inst_id)
        c["nodes"].append(inst)
        instances += 1

    # instances use the jcb language itself
    c["languages"] = c["languages"] + [{"key": LANG, "version":
                                        prop_of(lang_node, "Language-version")}]
    c["_stats"] = {**chunk.get("_stats", {}), "annotationInstances": instances}
    return c


# ---------------------------------------------------------------------------
# Variant 3: hoist shared definitions into Interfaces
# ---------------------------------------------------------------------------

def variant_interfaces(chunk):
    c = deepcopy(chunk)
    by_id = index(c)
    lang_node = by_id["jcb-language"]

    # cluster shared guids by the exact set of entities that use them
    clusters = defaultdict(list)
    for g, sites in guid_sites.items():
        if len(sites) < 2:
            continue
        clusters[frozenset(e for e, _ in sites)].append(g)

    # feature node ids grouped by (entity, property)
    site_to_feature = {}
    for fid, (e, p, g) in feature_site.items():
        if fid in by_id:
            site_to_feature[(e, p)] = fid

    made, hoisted, skipped = 0, 0, 0
    new_entity_ids = []

    for ents, guids in sorted(clusters.items(), key=lambda kv: -len(kv[1])):
        # a cluster is hoistable only if every member's feature node is
        # structurally identical (same classifier and same type target)
        hoistable = []
        for g in guids:
            sites = guid_sites[g]
            fids = [site_to_feature.get(s) for s in sites]
            if any(f is None for f in fids):
                skipped += 1
                continue
            shapes = set()
            for f in fids:
                n = by_id[f]
                shapes.add((n["classifier"]["key"],
                            ref_target(n, "Property-type") or ref_target(n, "Link-type"),
                            prop_of(n, "Link-multiple")))
            if len(shapes) != 1:
                skipped += 1
                continue
            hoistable.append((g, sites, fids))

        if not hoistable:
            continue

        iname = "IShared" + "".join(
            sorted(pascal(e) for e in ents)[:2]) + f"{len(ents)}x{len(hoistable)}"
        iid = "jcb-iface-" + iname
        iface = new_node(iid, "Interface", iname, iname, "jcb-language")
        ifeatures = []

        for g, sites, fids in hoistable:
            donor = by_id[fids[0]]
            # the shared feature is declared once, so its key can be the bare
            # guid - the identity JCB actually asserts
            nid = f"jcb-shared-{g}"
            moved = deepcopy(donor)
            moved["id"] = nid
            moved["parent"] = iid
            set_prop(moved, "IKeyed-key", g)
            c["nodes"].append(moved)
            ifeatures.append(nid)

            # remove the per-concept copies
            for f, (e, p) in zip(fids, sites):
                cid = f"jcb-{e}"
                owner = by_id[cid]
                set_children(owner, "Classifier-features",
                             [x for x in children_of(owner, "Classifier-features")
                              if x != f])
                c["nodes"] = [n for n in c["nodes"] if n["id"] != f]
                by_id.pop(f, None)
                hoisted += 1

        set_children(iface, "Classifier-features", ifeatures)
        c["nodes"].append(iface)
        new_entity_ids.append(iid)
        made += 1

        # every member concept implements the interface
        for e in ents:
            cid = f"jcb-{e}"
            if cid not in by_id:
                continue
            owner = by_id[cid]
            existing = []
            for r in owner.get("references", []):
                if r["reference"]["key"] == "Concept-implements":
                    existing = r["targets"]
                    r["targets"] = existing + [{"resolveInfo": iname, "reference": iid}]
                    break
            else:
                owner.setdefault("references", []).append(
                    {"reference": mp(M3, "Concept-implements"),
                     "targets": [{"resolveInfo": iname, "reference": iid}]})

    set_children(lang_node, "Language-entities",
                 children_of(lang_node, "Language-entities") + new_entity_ids)

    c["_stats"] = {**chunk.get("_stats", {}),
                   "interfaces": made, "featuresHoisted": hoisted,
                   "guidsSkipped": skipped}
    return c


# ---------------------------------------------------------------------------

def write(name, chunk):
    stats = chunk.pop("_stats", {})
    p = outdir / f"jcb-language.{name}.json"
    p.write_text(json.dumps(chunk, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    return p, stats


results = []

deduped = variant_dedupe(base)
annotated = variant_annotate(deduped)
interfaced = variant_interfaces(deduped)

for name, chunk in [("base", deepcopy(base)), ("dedupe", deduped),
                    ("annotate", annotated), ("interfaces", interfaced)]:
    p, stats = write(name, chunk)
    counts = defaultdict(int)
    for n in chunk["nodes"]:
        counts[n["classifier"]["key"]] += 1
    results.append((name, p, len(chunk["nodes"]), p.stat().st_size, dict(counts), stats))

print(f"{'variant':<12} {'nodes':>6} {'size':>10}  census")
for name, p, n, size, counts, stats in results:
    interesting = {k: v for k, v in sorted(counts.items())
                   if k in ("Concept", "Interface", "Enumeration", "Property",
                            "Containment", "Reference", "Annotation",
                            "JcbFieldDefinition")}
    print(f"{name:<12} {n:>6} {size/1024:>9.0f}K  {interesting}")
    if stats:
        print(f"{'':<12} {'':>6} {'':>10}  {stats}")

print(f"\nwritten to {outdir}")
