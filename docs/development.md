# Developing JcbInOut

The README says what JcbInOut is and what it has reached. This says how to work
on it: where things live, how to get a site to run it against, what the quality
gates are, and which decisions are load-bearing enough that changing them will
break something further down.

## Layout

Source lives under `/src`, laid out exactly as Joomla lays it out on disk. The
package mirrors the install path, so what is in the repository and what ends up
on a site are the same shape — the manifest's
`<files folder="administrator/components/com_jcbinout">` points at the very path
the files already sit in.

```
src/jcbinout.xml                      manifest
src/administrator/components/com_jcbinout/
  services/provider.php               DI registration
  src/
    Extension/                        component entry
    Controller/                       Display, Metamodel
    Model/MetamodelModel.php          orchestration, artefact storage
    View/Metamodel/ + tmpl/           status view
    Jcb/Locator.php                   finds and autoloads the installed JCB
    Metamodel/                        Extractor, Classifier, EnumHarvester, TableAdapter
    Blueprint/                        RepositorySource, Payload, DesignProjection
    Lionweb/                          LanguageBuilder, LanguageIndex,
                                      InstanceExporter, InstanceImporter, Validator
  data/                               reference artefacts, shipped
  language/en-GB/
build/build.php                       packages build/com_jcbinout-<version>.zip
tools/                                development scripts
data/                                 artefacts generated while developing
tests/                                PHPUnit, Cypress, fixtures
```

## One implementation

`tools/cli.php` is a harness, not a second implementation. It defines `_JEXEC`,
autoloads the component's own classes and calls them. Anything it can do, the
component does with the same code.

That is deliberate. The earlier arrangement had the pipeline as standalone
scripts, which meant none of it would have shipped: the component would have
carried four JSON files and no code to produce them. If you add a capability,
add it to a component class and let the CLI call it.

## The development site

`/joomla` holds a full Joomla install with JCB on it. It is git-ignored — a
working site, not source. CI builds its own.

```bash
php tools/setup-dev-site.php
```

Downloads the latest stable Joomla, creates `jlatest-jcbinout`, installs Joomla,
then installs JCB. `--force` replaces an existing site. `--db-suffix=` changes
the database name for a different repository.

| | |
|---|---|
| Admin | `admin` / `adminadminadmin` |
| Database | `jlatest-jcbinout`, user `root`, empty password |
| URL | http://localhost/jcbinout/joomla |

Two things need it. PHPStan resolves the component's base classes against a real
Joomla — without it every `extends BaseDatabaseModel` is an unknown class and
the analysis says nothing. Cypress needs a running site because whether a view
renders is exactly what unit tests cannot tell you; three bugs shipped past a
green unit suite and were caught the first time the component was opened in a
browser.

## Quality gates

```bash
composer test
```

```bash
composer analyse
```

```bash
composer cs
```

```bash
php tools/cli.php roundtrip
```

```bash
npx cypress run
```

Assert on `#system-message-container`, through the `messages()` helper, not on
`.alert`. `cy.get('.alert').invoke('text')` reads only the *first* match, and
Joomla enqueues messages of its own — the statistics prompt most of all — so a
spec asserting on a message the component definitely produced fails on the run
where Joomla got there first.

Specs must survive a second run. The suite writes to the dev site's database and
leaves the fixture there, so a spec that asserts the plan is all inserts is
green once and red afterwards — that is a spec testing the database rather than
the plan. Assert what holds whether or not the blueprint has been imported
before.

Cypress specs live in `tests/cypress/`, with everything else that tests
something. `cypress.config.js` and `cypress.env.json` stay at the repository
root, because that is where Cypress looks for them.

CI runs all of them; release runs them and then asserts what the package
contains. PHPStan is at level 5 and clean — fix findings rather than
baselining them, and if one is wrong about the code, the code is usually worth
changing anyway.

Coding standards split in two. The component's own tree follows Joomla's
standard, tabs and brace-on-its-own-line included, because its files sit beside
core's. Everything outside it — tests, build, tools — is ordinary PHP and
follows PSR-12. `phpcs.xml.dist` says which is which.

## The pipeline

```bash
php tools/cli.php all        # derive, build, validate
php tools/cli.php export     # blueprint -> LionWeb instance
php tools/cli.php roundtrip  # export, import, compare the design
```

**Derive** reads the installed JCB by reflection — `Table.php` for columns and
relations, `Factory.php` for the portable entity catalogue, the per-entity
`Remote\Config` classes for the transport projection — and writes
`jcb-metamodel.json`. Enumeration members are not in `Table.php`; they are
harvested from JCB's admin form XML and resolved against its language file.

It reads the JCB that is *installed*, not a copy pinned at build time. JCB
publishes no schema-compatibility policy, so the live installation is the only
thing that is reliably right. A fingerprint of `Table.php` is recorded with each
derivation, and the status view says when the installed schema has moved on.

**Build** turns that into a LionWeb language. **Export** turns a blueprint into
a LionWeb instance against that language. **Roundtrip** does both and compares.

## Decisions that are load-bearing

### Planning is separate from writing

`ImportPlanner` decides what an import would do; `LocalStore` does it. The split
is what makes initialize-versus-reset testable at all — the difference between
the two modes is entirely a matter of what the plan decides, not of how a row is
written — and it is what lets the UI show a candidate list before anything is
touched. If you add a decision, it belongs in the planner.

### A long import is sliced, and the cursor is the record

`ImportRun` is a cursor and a tally, and nothing else — no database, no session,
no clock. It is handed a slice's outcome and folds it in, which is why the
arithmetic that decides where a resumed import starts writing is testable at
all. Get it wrong one way and rows are written twice; the other way and they are
never written, and neither shows up in a green test of the writer.

Two rules there are load-bearing. The cursor advances by what the writer says it
**consumed**, not by what it applied, or a failing row would be re-offered on
every slice and the run would never end. And `LocalStore::apply()` always writes
**at least one** operation whatever the deadline says, or a budget already spent
on arrival would consume nothing and the run would never end either.

The deadline lives in the writer rather than the planner on purpose: what to
write is a decision, how long a write takes is not.

### Both sides produce the same payloads

`RepositorySource` reads a blueprint off disk and `DatabaseSource` reads JCB's
tables, and everything downstream works on `Payload` without being told which.
So the two have to be indistinguishable, and that is checked rather than hoped
for: the fixture is imported into the dev site, so the same models can be read
both ways and compared by β. They agree column for column.

Three things came out of running that comparison for the first time, and none
of them was visible from a green unit suite:

- **A JSON column holding `''` or `[]` is nothing, not an empty value.** The
  column's SQL default is `''`, an emptied subform leaves `[]`, and a payload
  carries `null` for both. In a *text* column `''` is still an empty string —
  the projection is right to notice that difference, so `Schema::decode()`
  decides by the column's store.
- **Ownership is declared, not inferred.** `custom_code`, `placeholder` and
  `validation_rule` are identified by a natural key rather than a guid and are
  nobody's children. Reading "no guid" as "owned record" filed them under a
  parent that does not exist. Ask `Schema::parentOf()`.
- **Not every JCB identity is a legal LionWeb id.** A `placeholder` is addressed
  by its target and a target reads `[[[COMPANY]]]`, which no chunk will accept.
  `Payload::nodeId()` leaves anything already legal alone — every GUID, so
  nothing that used to export changes — and encodes the rest, keeping a
  readable stem and appending a hash so two targets differing only in
  punctuation stay two nodes.

Only the last needed a whole installation to find. One blueprint has no
placeholders in it.

### Where a record lives is declared, not derived

`srcPath`, `settingsName` and `indexPath` come from JCB's transport config, and
they are not all the obvious ones. `power` keeps its payload in `settings.json`
at the root of `src`; every owned record is filed under its parent. Ask
`Schema::payloadPath()` rather than building a path, on both sides.

Writing the fourth direction is what exposed how far that goes. `RepositorySource`
found payloads with a regex that assumed `src/<entity>/<guid>/item.json`, so
five portable entities — 365 of the dev site's 824 records — were invisible to
it. It now takes an optional `Schema` and looks for the declared patterns too.

And four of those five cannot be written at all: `joomla_power`, `snippet`,
`fieldtype` and `repository` declare the *same* layout as each other, so a
record found there cannot be attributed to one of them. Both sides say so —
`LAYOUT_NOT_DISTINCT` when writing, `AMBIGUOUS_LAYOUT` when reading — and
neither guesses. If a real JCB repository turns up that holds these, the
question to answer is how JCB itself tells them apart; the index is the likely
answer, but three of the four name the same index file.

### The node id is not the identity

`Payload::nodeId()` encodes an identity that is not a legal LionWeb id, so
`InstanceImporter` must not read the identity back off the id — it takes it
from the identifying column instead, which needs the `Schema` and is why the
importer accepts one. Without that, eleven placeholders came back named
`COMPANY-35db07a3009b` and an import would have written that to JCB as their
target.

Encoding one way and decoding another is the general shape of this mistake, and
it passes any test that only exports.

### The metamodel owns storage, the language owns design

The language says what a column *is*. It says nothing about how JCB writes the
value, because that is storage and no part of the portable design. `Jcb\Schema`
answers the storage questions from the derived metamodel: which table, which
identifying column, and whether the value is base64, JSON or neither. An import
that skipped the re-encoding would write JSON where JCB expects base64 and
produce rows the compiler cannot read.

### The language is the authority, not the metamodel

`LanguageIndex` reads the generated language back rather than re-deriving
anything from `jcb-metamodel.json`. Deduplication and interface hoisting both
change what a column's feature key and type actually are, so re-deriving those
decisions at export time would be a second implementation free to drift from the
first.

### A column GUID is a field definition's identity

The `guid` on a column in `Table.php` is not a column id. It is the portable
identity of the JCB *field definition* that generated the column — verifiable in
the public trace, where the Hello World blueprint references field
`75e830a6-…` and the generated component's `Helloworld/Table.php` carries that
same GUID on its `greeting` column.

So one GUID on several entities means one reusable definition used at several
use-sites. 104 of JCB's field definitions are shared that way. The language says
so structurally: shared definitions are declared once in an interface that every
using concept implements, keyed by the bare GUID, and enumerations and row
concepts reached through one definition are emitted once.

Interface names live in `data/interface-names.json`. Plain data — edit freely;
the generator warns if a cluster's signature drifts away from a configured name.

### Occurrences are reified, never cloned

A subform row such as `admin_fields.addfields[i]` holds a reference to a `field`
definition plus the roles that use-site gives it. It becomes its own node, so
the definition stays a single node however many views use it. If you find
yourself copying a definition's properties into a use-site, stop.

### The type follows the column, not the widget

A JCB radio backed by `TINYINT(1)` is not a yes/no: MySQL's `(1)` is a display
width, the column holds −128..127, and JCB stores 2 and 3 in some of them.
Reading them as Boolean destroyed the value. Nor is every radio numeric —
`add_namespace_prefix` is a radio on `CHAR(1)` whose default is `''`.
`Classifier` decides from the SQL column, which is the only thing that knows.

### What the round trip compares

`DesignProjection` computes β, the portable design projection — not byte
equality. Its docblock is the specification; read it before changing it. In
short it ignores `@dependencies`, subform row *keys* (row *order* is compared),
absent-versus-null, scalar type, columns the language does not model, and JCB's
no-value sentinels by declared type. It does not ignore an empty string in a
text column.

A comparison that cannot fail is worth nothing, so `RoundTripTest` asserts that
a changed value, a dropped column, a missing payload and a reordered subform are
all reported, and that renumbered row keys are not. Keep those.

## Building and releasing

```bash
php build/build.php
```

Writes `build/com_jcbinout-<version>.zip`, taking the version from
`src/jcbinout.xml`. The zip is git-ignored.

A release is a tag. `release.yml` checks the tag against the manifest before
building, runs every gate, then asserts the package carries the manifest at its
root and the reference artefacts, and nothing from `vendor/`, `node_modules/` or
`joomla/`. Bump `src/jcbinout.xml`, commit, tag `v<version>`, push the tag.

## Known limits

- **An import is not transactional.** Each row is its own statement and its own
  outcome, so a failure part-way leaves what was already written in place, with
  a list of what failed. That is deliberate — and it is why a run keeps a cursor
  — but it means a failed import wants reading rather than re-running blindly.
- **A slice's counts are lost if its request is killed.** The checkpoint records
  where the writing got to, not what it did, so a run resumed after a crash
  resumes in the right place with its applied/failed totals short by up to the
  24 rows since the last checkpoint. The rows are right; the tally is not.
- **`max_execution_time` is not the only way a request dies.** The deadline is a
  best effort against the limit PHP reports. A proxy timeout or the memory limit
  will still cut a slice short — which the checkpoint covers, at the cost above.
- **Row node ids are not stable across a round trip.** Definitions keep their
  GUIDs, but subform row ids derive from JCB's row keys, which the importer
  renumbers from zero. Inside β, but it matters if anything starts diffing
  chunks rather than designs.
- **Four unshaped JSON columns travel as opaque strings.** β compares them
  structurally so they round-trip, but they are not modelled.
- **One fixture.** Hello World is 33 payloads of JCB's own small example. It
  reaches 21 of the 45 portable entity types. The Service Directory blueprint
  (`joomengine/joomla-packages`) would be a worthwhile second corpus.
- **Cypress is not in CI.** It needs a running site with JCB on it. Adding a
  job that installs both is possible and slow.
