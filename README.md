# JcbInOut

LionWeb-compatible import/export for Joomla Component Builder blueprints.

The goal is to move JCB models in and out of a neutral, standard format so they
can be exchanged with other LionWeb-compatible tooling — including the Exten-gen
extension generator.

## Status

**Phases 1-2 complete:** JCB's implicit metamodel is explicit, and published as a
LionWeb language that an independent LionWeb implementation loads.

Phases, per the project plan:

| Phase | What | Status |
|---|---|:---:|
| 1 | Make the implicit metamodel explicit | done |
| 2 | LionWeb language definition (M2) | done |
| 3 | Instance export (M1) | next |
| 4 | Round-trip against the Hello World fixture | |
| 5 | Import with initialize/reset policy | |
| 6 | Package as a Joomla component | |
| 7 | Exten-gen side | |

## Phase 1 — the derived metamodel

JCB has no declared metamodel, but it does have a machine-readable one spread
across three places:

| Source | Holds |
|---|---|
| `Componentbuilder/Table.php` | entity properties: name, **stable GUID**, type, store, SQL column, `link` relations |
| `Componentbuilder/Factory.php` | the canonical portable entity catalogue |
| `**/Remote/Config.php` | per-entity transport: identifying field, children, `ignore` list, paths |

`bin/extract-metamodel.php` loads those real classes and reads them **by
reflection** — never by parsing PHP text — so the result tracks JCB upstream
instead of drifting from it.

### Results

- **51** entity types defined, **45** portable (independently matching JCB's own
  published count of canonical transport types)
- **699** properties classified into LionWeb feature kinds
- **22** occurrence concepts identified — subform rows that carry a reference
  *plus* use-site settings, i.e. the definition/occurrence split as it actually
  exists in JCB's data

Validated against the public Hello World blueprint: **33 payloads, 646 keys,
19 subform rows, zero unexplained.**

See [METAMODEL.md](METAMODEL.md) for the readable report and
`jcb-metamodel.json` for the machine-readable output.

## Phase 2 - the LionWeb language

`bin/generate-lionweb-language.php` turns the derived metamodel into a LionWeb
serialisation chunk: **1,422 nodes** — 114 concepts, 720 properties, 79
references, 112 containments, 18 enumerations with 378 literals.

Validated three ways:

- against the official `serialization.schema.json` (LionWeb 2024.1)
- structurally: unique ids, resolvable children, parent/containment agreement,
  single root, every metapointer a real LionCore key
- **independently**, by loading it in `lionweb-python` 0.1.16

### Feature keys and shared field identity

JCB assigns a GUID to every column in `Table.php`. That GUID is **not** a column
id — it is the portable identity `u = (field, guid, v)` of the JCB *field
definition* that generated the column.

This is verifiable in the public trace: the Hello World blueprint references
field `75e830a6-a3a5-4327-9161-3f774a6f1591` from `admin-fields.json`, and the
generated component's `Helloworld/Table.php` carries that same GUID on its
`greeting` column (and again in `db.GUID`). JCB is self-generated, so its own
`Table.php` is the same artifact for its own blueprint.

The consequence: where one GUID appears on several entities, JCB is asserting
that those columns are **one reusable field definition used at several
use-sites** — the definition/occurrence distinction, one level up. 104 of the
586 feature keys are such shared definitions: `guid` across 20 entities, `name`
across 16, `system_name` across 10, the parent back-reference
`joomla_component` across 12.

LionWeb requires feature keys to be unique language-wide (LionCore itself
qualifies: `Concept-extends` vs `Annotation-extends`), so keys are
`<entity>-<guid>`. The GUID still survives a column rename, which is the point.

`jcb-feature-keys.json` therefore carries semantics, not bookkeeping: it records
which properties across the 45 entity types are the same field definition. That
relation is not recoverable from names — `field.name` and
`joomla_plugin_group.name` share a definition while other same-named properties
do not.

**Open design question.** Two better encodings exist, both deferred because they
change the language shape:

- a `JcbFieldDefinition` **annotation** carrying the guid, attached to every
  feature, so the identity lives in the language file rather than a side-car;
- **interfaces** for shared definitions, which is what "one definition, many
  occurrences" means at M2 — though 104 single-feature interfaces would want
  clustering by co-occurrence first.

### Outputs

| File | Purpose |
|---|---|
| `jcb-language.lionweb.json` | the LionWeb language (M2) |
| `jcb-enum-values.json` | enum literal name -> JCB raw value, both directions |
| `jcb-feature-keys.json` | feature key -> (entity, property, guid) + shared-GUID map |

## Usage

```bash
# 1. vendor the pinned JCB sources
bin/fetch-jcb-sources.sh

# 2. derive the metamodel
php bin/extract-metamodel.php vendor-jcb jcb-metamodel.json

# 3. validate it against a real blueprint
php bin/validate-against-blueprint.php jcb-metamodel.json tests/fixtures/hello-world

# 4. render the readable report
php bin/report-metamodel.php jcb-metamodel.json METAMODEL.md

# 5. generate the LionWeb language
php bin/generate-lionweb-language.php jcb-metamodel.json jcb-language.lionweb.json

# 6. validate it (schema + structure + independent implementation)
python bin/validate-lionweb.py jcb-language.lionweb.json vendor-lionweb
```

Phase 2 validation needs Python with `jsonschema` and `lionweb-python`.

Requires PHP 8.1+. No Joomla installation needed for Phase 1 — the classes load
standalone.

## Design notes

**Fidelity target is β-equivalence, not byte equality.** Each entity's `ignore`
list is honoured, and installation-local values (encrypted credentials, local
row ids, `access`/`catid`) are excluded. This matches what JCB itself considers
portable.

**Property GUIDs become LionWeb feature keys.** JCB already assigns a stable
GUID to every column, so both sides can agree on feature identity without a
negotiated registry, and a renamed column keeps its key.

**Occurrences are reified, never cloned.** A subform row such as
`admin_fields.addfields[i]` holds a reference to a `field` definition plus its
roles (title, list, sort, search, order). It becomes its own node, so one
definition can be referenced from many use sites.

## Pinned versions

| | |
|---|---|
| JCB | `bca4a1520484f3e2c2fbd12964a5995b0d058de1` |
| Hello World fixture | `5802e7c1d9bfaac005c765ccda830a7d07cd7e12` |
| LionWeb | 2024.1 (`lionweb-io/specification`) |

JCB publishes no schema-compatibility policy, so the pin plus the fixture
corpus in CI is the protection against silent format drift.
