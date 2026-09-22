# JcbInOut

LionWeb-compatible import and export for Joomla Component Builder blueprints,
packaged as a Joomla component.

The goal is to move JCB models in and out of a neutral, standard format so they
can be exchanged with other LionWeb-compatible tooling — including the Exten-gen
extension generator.

---

## Status

**Working today:** a Joomla component that reads the JCB installed on the same
site, derives an explicit metamodel from it, publishes that metamodel as a
LionWeb language, and exports a JCB blueprint as a LionWeb instance against it.
Install it, then *Derive*, *Build*, *Export*.

**Working end to end:** a blueprint goes out to LionWeb, comes back with its
design intact, and can be written into the JCB on the same site — planned first,
applied only on a separate decision.

| Phase | What | Status |
|---|---|:---:|
| 1 | Make JCB's implicit metamodel explicit | **done** |
| 2 | LionWeb language definition (M2) | **done** |
| 6a | Joomla component shell, derive + build in the UI | **done** |
| 3 | Instance export (M1): blueprint to LionWeb | **done** |
| 4 | Round-trip against the Hello World fixture | **done** |
| 5 | Import into JCB, with initialize/reset policy | **done** |
| 6b | Progress reporting for large blueprints | **done** |
| 7 | Exten-gen side | next |

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

### Two sides, and which of them is which

A JCB model lives in two places, and they are not the same place. Worth being
explicit, because "export from JCB" could reasonably mean either:

| | reads | writes |
|---|---|---|
| Export blueprint | a blueprint repository on disk | a LionWeb chunk |
| Export installed | JCB's tables | a LionWeb chunk |
| Import | a LionWeb chunk | JCB's tables |
| Write blueprint | a LionWeb chunk | a blueprint repository on disk |

A repository is a blueprint somebody has already pushed. **Export installed**
is how a component still being built in JCB's own interface gets out — which is
most of them, most of the time — and **Write blueprint** is how a model that
arrived as a chunk becomes a tree of JSON files you can read, diff and commit.

Every reader produces the same `Payload` objects and the writer consumes them,
so nothing in between is ever told which side a model came from. That is
checkable rather than merely intended, and both halves are checked: the fixture
is imported into the dev site, so the same models can be read off disk and out
of the tables and compared by β — they agree on all 33, column for column — and
the whole installation can be taken out of the tables, through a chunk, onto
disk and read back, which comes to 600 payloads with no design difference at
all.

**Four entities cannot go into a repository**, and are declined rather than
written wrong. `joomla_power`, `snippet`, `fieldtype` and `repository` all
declare the same layout in JCB's transport config — `src/<guid>/item.json`,
with three of them naming the same index — so a record of one cannot be told
from a record of another once it is on disk. That is 223 of the dev site's 824
records. Writing them anyway would produce a tree that reads back as the wrong
entity, which is worse than one that is honestly incomplete.

### Instance export

`Blueprint\RepositorySource` reads a blueprint repository and `Jcb\DatabaseSource`
reads the tables; `Lionweb\LanguageIndex` reads the generated language back;
`Lionweb\InstanceExporter` turns payloads into a LionWeb instance chunk against
the language.

The language is the authority, not the metamodel it came from. Deduplication and
interface hoisting both change what a column's feature key and type are, so
re-deriving those decisions at export time would be a second implementation free
to drift from the first.

Against the Hello World fixture: **33 payloads to 53 nodes**, 595 properties,
43 references, one partition root, every node reachable from it. The chunk
validates, and loads in `lionweb-python` once the language is registered.

What the export preserves:

- **Definitions keep their JCB guid as their node id.** That guid is the
  portable identity the blueprint is built around.
- **Occurrences are reified, not cloned.** The Greeting field is one `field`
  node; the view that uses it holds an `AdminFieldsAddfieldsRow` referencing it
  and carrying `title`, `search`, `sort` — the use-site roles.
- **Enumerations carry literal keys**, so `VARCHAR` becomes
  `FieldDatatype-VARCHAR` rather than a raw column value.
- **Unset is absent.** JCB writes `''` for an unselected list and `'0'` for an
  unselected reference; neither becomes a property or a dangling target.
- **References outside the blueprint keep their target** as `resolveInfo` with
  no id. Hello World genuinely references field types living in
  `joomengine/joomla-fieldtypes`, and that is not an error.

### The round trip

`Lionweb\InstanceImporter` reads a chunk back into blueprint payloads, and
`Blueprint\DesignProjection` compares two blueprints under β — the portable
design projection, not byte equality.

```bash
php tools/cli.php roundtrip
```

> `Round trip: 33 payloads out, 53 nodes, 33 payloads back`
> `PASS - design preserved across 33 payloads.`

**β is published, in `DesignProjection`'s own docblock, and it ignores:**
`@dependencies` (transport instructions, not design); subform row *keys* (JCB
regenerates them — row *order* is compared, because an ordered association is
not a set); absent versus null; scalar type; columns the language does not model
(anything JCB itself marks installation-local); and JCB's no-value sentinels by
declared type — `''` in a list column, `'0'` in a reference column.

It does **not** ignore an empty string in a text column. That is a value.

The comparison is only worth something if it can fail, so `RoundTripTest`
includes negative controls: a changed value, a dropped column, a missing
payload and a reordered subform must all be reported, while renumbered row keys
must not.

### Import

Planning and writing are separate calls, and the UI offers them as separate
buttons. A plan is a candidate list: it can be shown, counted and argued with
before a single row is touched, and it can be tested without a database — which
is why every decision lives in `Blueprint\ImportPlanner` and only the execution
lives in `Jcb\LocalStore`.

```bash
php tools/cli.php plan          # what an import would do, no database needed
php tools/cli.php plan reset
```

Two modes, and the difference between them is entirely a matter of what the
plan decides:

| Mode | A definition already here | One that is missing |
|---|---|---|
| `initialize` | left alone, so local edits survive | inserted |
| `reset` | refreshed from the blueprint | inserted |

What the planner does beyond that: drops columns JCB itself marks
installation-local, leaves Joomla's own columns to the writer (which sets them
on an insert and never touches them on an update), re-encodes values the way JCB
stores them — 144 base64 columns and 90 JSON ones — and orders the writes so a
row is written after whatever it points at.

### A long import is sliced

A plan of any size is applied in slices, one request each, with a progress bar
between them. The bar is the by-product; the point is that a blueprint big
enough to matter will not finish inside one request, and an import is not
transactional — so the difference between slicing it and not is whether
anything knows how far it got.

`Blueprint\ImportRun` holds the cursor and the totals and travels in the
session. `LocalStore` stops at the next operation boundary once
`max_execution_time` is 60% spent and reports how many it consumed. It always
writes at least one operation whatever the deadline says, because a run that
consumes nothing never ends.

A request can still be killed by something that is not the execution limit, so
every twenty-fifth write leaves a checkpoint on disk. The next request resumes
from that rather than from the cursor the session last saw, and rows already in
JCB are not written a second time.

**Rows per request** caps a slice regardless of time. Leave it at 0 on a host
you control; set it on a shared one that is short of more than execution time.

The page advances itself, with **Pause** to stop and **Stop import** to abandon
the run — which leaves what has already been written exactly where it is, because
there is no undo here and offering something that only looked like one would be
worse. With no JavaScript at all, **Continue import** does the same thing one
slice at a time.

Verified against the live dev site: the Hello World blueprint imports into JCB
6.1.6 as real rows, with `datatype` back to `VARCHAR` from its literal key and
`php_getitem` back to base64 that decodes byte-for-byte to the source.

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

Source lives under `/src`, laid out exactly as Joomla lays it out on disk. The
package mirrors the install path, so what is in the repository and what ends up
on a site are the same shape.

```
src/                                  the extension, as installed
  jcbinout.xml                        manifest
  administrator/components/com_jcbinout/
    services/provider.php             DI registration
    src/
      Extension/                      component entry
      Controller/                     Display, Metamodel (derive/build/export/validate)
      Model/MetamodelModel.php        orchestration, artefact storage
      View/Metamodel/                 status view
      Jcb/Locator.php                 finds and autoloads the installed JCB
      Metamodel/                      Extractor, Classifier, EnumHarvester, TableAdapter
      Blueprint/                      RepositorySource, Payload, DesignProjection
      Lionweb/                        LanguageBuilder, LanguageIndex,
                                      InstanceExporter, InstanceImporter, Validator
    tmpl/metamodel/default.php        status view template
    data/                             reference artefacts, shipped with the package
    language/en-GB/
build/
  build.php                           packages build/com_jcbinout-<version>.zip
data/                                 artefacts generated during development
tools/
  cli.php                             dev harness over the component's own classes
  setup-dev-site.php                  creates the development Joomla site
  report-metamodel.php                renders METAMODEL.md
  phpstan-bootstrap.php               Joomla's runtime constants, for analysis
tests/
  Unit/                               PHPUnit
  cypress/                            end-to-end specs
  fixtures/                           Hello World blueprint
```

## Development site

The repository carries a git-ignored `/joomla` holding a full Joomla install
with JCB on it. PHPStan needs it to resolve `extends BaseDatabaseModel`, and
Cypress needs it because whether a view renders is exactly what unit tests
cannot tell you.

| | |
|---|---|
| Joomla | 6.1.3, in `/joomla` |
| Admin | `admin` / `adminadminadmin` |
| Database | `jlatest-jcbinout`, user `root`, empty password |
| JCB | 6.1.6 |
| URL | http://localhost/jcbinout/joomla |

To recreate it:

```bash
php tools/setup-dev-site.php
```

## Usage

### Build and install the component

```bash
php build/build.php
```

Install `build/com_jcbinout-0.3.0.zip` on a Joomla site that has JCB, then open
Components → JcbInOut. The status view reports what it found; the toolbar offers
*Derive metamodel*, *Build LionWeb language* and *Validate*.

### The pipeline, without Joomla

`tools/cli.php` runs the component's own classes from the command line. It
prefers the development site when one is present, and falls back to a vendored
copy of JCB's sources pinned at a known commit.

```bash
php tools/cli.php fetch
```

```bash
php tools/cli.php all
```

Export a blueprint against the built language:

```bash
php tools/cli.php export tests/fixtures/hello-world
```

Export, import, and compare the design:

```bash
php tools/cli.php roundtrip
```

### Checks

```bash
composer test && composer analyse && npx cypress run
```

Requires PHP 8.3+, `ext-zip`, Node 20+ and MySQL. PHPUnit and PHPStan need no
running site; Cypress does.

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

## Developing

[docs/development.md](docs/development.md) covers the layout, the development
site, the quality gates, and the decisions that are load-bearing enough that
changing them breaks something downstream.

## Licence

GNU General Public License version 3 or later. See [LICENSE](LICENSE).

## Pinned versions

| | |
|---|---|
| JCB (development baseline) | `bca4a1520484f3e2c2fbd12964a5995b0d058de1` |
| Hello World fixture | `5802e7c1d9bfaac005c765ccda830a7d07cd7e12` |
| LionWeb | 2024.1 |
