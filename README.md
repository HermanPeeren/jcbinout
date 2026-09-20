# JcbInOut

LionWeb-compatible import and export for Joomla Component Builder blueprints,
packaged as a Joomla component.

The goal is to move JCB models in and out of a neutral, standard format so they
can be exchanged with other LionWeb-compatible tooling — including the Exten-gen
extension generator.

---

## Status

**Working today:** a Joomla component that reads the JCB installed on the same
site, derives an explicit metamodel from it, and publishes that metamodel as a
LionWeb language. Install it, click *Derive*, click *Build*.

**Not built yet:** the actual import and export of blueprints. That is Phase 3
onward. Today's component establishes the metamodel those phases need.

| Phase | What | Status |
|---|---|:---:|
| 1 | Make JCB's implicit metamodel explicit | **done** |
| 2 | LionWeb language definition (M2) | **done** |
| 6a | Joomla component shell, derive + build in the UI | **done** |
| 3 | Instance export (M1): blueprint to LionWeb | next |
| 4 | Round-trip against the Hello World fixture | |
| 5 | Import, with initialize/reset policy | |
| 6b | Export/import UI, progress, diagnostics | |
| 7 | Exten-gen side | |

Phase 6 was originally scheduled last. It moved forward because leaving it late
meant writing Phase 3 as standalone scripts and porting them afterwards — the
component shell exists now so export code lands in the right place first time.

### Everything is PHP

No Python, no bash, no external processes. The component runs inside an ordinary
Joomla request. `tools/cli.php` runs the **same classes** outside Joomla for
development and regression testing, so there is no second implementation to
drift.

---

## How it works

JCB has no declared metamodel, but it has a machine-readable one spread across
three places:

| Source | Holds |
|---|---|
| `Componentbuilder/Table.php` | entity properties: name, **stable GUID**, type, store, SQL column, `link` relations |
| `Componentbuilder/Factory.php` | the canonical portable entity catalogue |
| `Remote/Config.php` (per entity) | transport: identifying field, children, `ignore` list, paths |

`Metamodel\Extractor` loads those real classes and reads them **by reflection** —
never by parsing PHP text. Enumeration members are not in `Table.php`; they are
harvested from JCB's admin form XML and resolved against its language file.

Crucially, the component reads **the JCB that is actually installed**, not a copy
pinned when JcbInOut was built. JCB publishes no schema-compatibility policy, so
reading the live installation is the only way to stay correct across versions.
The component records a fingerprint of `Table.php` with each derivation and warns
when the installed schema has moved on.

### Results against JCB `bca4a15`

- **51** entity types, **45** portable — independently matching JCB's own
  published count of canonical transport types
- **699** properties classified into LionWeb feature kinds
- **18** enumerations, **378** literals, harvested from the admin forms
- **22** occurrence concepts — subform rows carrying a reference *plus* use-site
  settings, the definition/occurrence split as it exists in data
- LionWeb language: **1,082 nodes** — 93 concepts, 33 interfaces, 11
  enumerations, 481 properties, 46 references, 91 containments

Validated against the public Hello World blueprint: **33 payloads, 646 keys,
19 subform rows, zero unexplained.** The language validates structurally and
loads in `lionweb-python` with no setup.

### Shared field definitions

A column's GUID in `Table.php` is not a column id. It is the portable identity
`u = (field, guid, v)` of the JCB *field definition* that generated the column.

This is verifiable in the public trace: the Hello World blueprint references
field `75e830a6-a3a5-4327-9161-3f774a6f1591` from `admin-fields.json`, and the
generated component's `Helloworld/Table.php` carries that same GUID on its
`greeting` column. JCB is self-generated, so its own `Table.php` is the same
artefact for its own blueprint.

So where one GUID appears on several entities, JCB is asserting that those
columns are **one reusable definition used at several use-sites**. 104 of its
field definitions are shared that way, and the language encodes it directly:

- **Deduplication** — enumerations and subform row concepts reached through one
  definition are the same type, so they are emitted once. Removes 7 duplicate
  enumerations and 21 duplicate row concepts.
- **Interfaces** — each shared definition is declared once, in an interface that
  every using concept implements. Declared once means its feature key is the
  **bare GUID**, the identity JCB actually asserts.

Interface names live in `data/interface-names.json`. Plain data — edit freely.

| Interface | Entities | Holds |
|---|---|---|
| `IPortableIdentity` | 20 | `guid` |
| `IInstallableExtension` | component, module, plugin | install scripts, update server |
| `IRenderedView` | custom_admin_view, site_view | css, js, php_jview, main_get |
| `IColumnStorage` | field, fieldtype | datatype, datalenght, indexes, store |
| `IComponentChild` | 12 `component_*` | back-reference to `joomla_component` |

---

## Layout

```
com_jcbinout/                     the installable component
  jcbinout.xml                    manifest
  administrator/
    services/provider.php         DI registration
    src/
      Extension/                  component entry
      Controller/                 Display, Metamodel (derive/build/validate)
      Model/MetamodelModel.php    orchestration, artefact storage
      View/Metamodel/             status view
      Jcb/Locator.php             finds and autoloads the installed JCB
      Metamodel/                  Extractor, Classifier, EnumHarvester, TableAdapter
      Lionweb/                    LanguageBuilder, Validator
    tmpl/metamodel/default.php    status view template
    data/                         reference artefacts, shipped with the package
    language/en-GB/
data/                             artefacts generated during development
tools/
  cli.php                         dev harness over the component's own classes
  build.php                       packages the installable zip into dist/
  report-metamodel.php            renders METAMODEL.md
tests/fixtures/                   Hello World blueprint, for round-trip tests
```

## Usage

### As a component

```bash
php tools/build.php
```

Install `dist/com_jcbinout-0.2.0.zip` on a Joomla site that has JCB, then open
Components → JcbInOut. The status view reports what it found; the toolbar offers
*Derive metamodel*, *Build LionWeb language* and *Validate*.

### For development, without Joomla

Vendor JCB's sources at the pinned commit:

```bash
php tools/cli.php fetch
```

Then derive, build and validate in one go:

```bash
php tools/cli.php all
```

Requires PHP 8.1+, plus `ext-zip` for packaging. Nothing else.

---

## Design notes

**Fidelity target is β-equivalence, not byte equality.** Each entity's `ignore`
list is honoured, and installation-local values (encrypted credentials, local row
ids, `access`/`catid`) are excluded. This matches what JCB itself treats as
portable.

**Occurrences are reified, never cloned.** A subform row such as
`admin_fields.addfields[i]` holds a reference to a `field` definition plus its
roles. It becomes its own node, so one definition can be referenced from many
use-sites.

**Derive live, ship a reference.** The package includes artefacts built against
JCB `bca4a15` so a fresh install has a baseline to compare against, but the
component always prefers what it derives from the installed JCB.

## Pinned versions

| | |
|---|---|
| JCB (development baseline) | `bca4a1520484f3e2c2fbd12964a5995b0d058de1` |
| Hello World fixture | `5802e7c1d9bfaac005c765ccda830a7d07cd7e12` |
| LionWeb | 2024.1 |
