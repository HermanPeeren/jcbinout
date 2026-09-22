/**
 * The status view and the derive/build pipeline, against a real site.
 *
 * These specs exist because the PHPUnit suite has no Joomla in it. A component
 * whose classes are all individually correct can still render a blank page,
 * fail to find the JCB installed beside it, or write its artefacts somewhere
 * the web server cannot.
 */
/**
 * The message area's text, all of it.
 *
 * Not `cy.get('.alert').invoke('text')`, which reads only the first match:
 * Joomla enqueues its own messages - the statistics prompt most of all - and
 * one of those arriving first made a passing spec fail on a page where the
 * component had done exactly what was asked.
 */
const messages = (options = {}) =>
  cy.get('#system-message-container', { timeout: 60000, ...options }).invoke('text');

describe('JcbInOut metamodel view', () => {
  beforeEach(() => {
    cy.openJcbInOut('metamodel');
  });

  it('renders and reports the JCB it found', () => {
    cy.contains('h1', 'JCB Metamodel').should('be.visible');

    // The component is useless without JCB, so the first thing it must do is
    // say clearly whether it found it.
    cy.contains('Joomla Component Builder').should('be.visible');
    cy.contains('Installed and enabled').should('be.visible');

    // A version, not an empty cell: an installed JCB whose version cannot be
    // read means the manifest cache is not being consulted correctly.
    cy.contains('dt', 'Version')
      .next('dd')
      .invoke('text')
      .should('match', /\d+\.\d+/);

    // Locator has to resolve the source path, or nothing can be reflected over.
    cy.contains('Schema classes').should('be.visible');
    cy.contains('Readable').should('be.visible');
  });

  it('offers Derive when JCB is present', () => {
    cy.get('#toolbar').contains('Derive metamodel').should('be.visible');
  });

  it('derives a metamodel and reports what it found', () => {
    cy.get('#toolbar').contains('Derive metamodel').click();

    // The summary is the point: a green tick that says nothing would not tell
    // us whether 51 entity types or zero were read.
    messages({ timeout: 60000 })
      .should('match', /entity types/);

    cy.contains('Entity types').should('be.visible');

    // JCB publishes 45 canonical transport entity types. Deriving a different
    // number means the catalogue moved, and that is worth failing over.
    cy.contains('dt', 'Entity types')
      .next('dd')
      .invoke('text')
      .should('match', /45\s+portable/);
  });

  it('builds a LionWeb language from the derived metamodel', () => {
    cy.get('#toolbar').contains('Derive metamodel').click();
    cy.contains('Entity types', { timeout: 60000 }).should('be.visible');

    cy.get('#toolbar').contains('Build LionWeb language').click();

    messages({ timeout: 60000 })
      .should('match', /nodes:|concepts/);

    // The census table is what shows the language actually has shape.
    cy.contains('LionWeb language').should('be.visible');
    cy.contains('td', 'Concept').should('be.visible');
    cy.contains('td', 'Interface').should('be.visible');
  });

  it('exports a blueprint as a LionWeb instance', () => {
    // Derive and build first: export needs a language to export against, and
    // each spec starts from whatever the previous one left on disk.
    cy.get('#toolbar').contains('Derive metamodel').click();
    cy.contains('Entity types', { timeout: 60000 }).should('be.visible');

    cy.get('#toolbar').contains('Build LionWeb language').click();
    cy.contains('td', 'Interface', { timeout: 60000 }).should('be.visible');

    // The shipped Hello World blueprint is pre-filled, so this is the path a
    // fresh install would export without being told anything.
    cy.get('#blueprint').invoke('val').should('not.be.empty');

    cy.get('#toolbar').contains('Export blueprint').click();

    messages({ timeout: 60000 })
      .should('match', /payloads read/);

    // 33 payloads is the published size of the fixture; asserting it means a
    // silently truncated walk fails here rather than looking like success.
    messages()
      .should('match', /33 payloads read/);

    // Scope to the export card: "Exported" on its own also matches Joomla's
    // <noscript> boilerplate elsewhere on the page.
    cy.get('#blueprint')
      .closest('.card')
      .within(() => {
        cy.get('.badge').should('contain.text', 'Exported');
      });
  });

  it('plans an import without writing anything, then applies it', () => {
    // Derive, build and export first: each spec starts from whatever the
    // previous one left on disk, and a plan needs something to plan.
    cy.get('#toolbar').contains('Derive metamodel').click();
    cy.contains('Entity types', { timeout: 60000 }).should('be.visible');

    cy.get('#toolbar').contains('Build LionWeb language').click();
    cy.contains('td', 'Interface', { timeout: 60000 }).should('be.visible');

    cy.get('#toolbar').contains('Export blueprint').click();
    cy.get('#blueprint', { timeout: 60000 }).should('exist');

    // Planning must never write. Applying is offered only afterwards, so the
    // write is always a decision taken about a plan that was shown.
    cy.get('#toolbar').contains('Apply plan').should('not.exist');

    cy.get('#mode').select('initialize');
    cy.get('#toolbar').contains('Plan import').click();

    messages({ timeout: 60000 })
      .should('match', /33 payloads/);

    // Scope to the import card: bare text also matches Joomla's <noscript>.
    cy.get('#mode').closest('.card').within(() => {
      cy.get('.alert').should('contain.text', 'Nothing has been written yet');
    });

    cy.get('#toolbar').contains('Apply plan').should('be.visible');

    // Every payload is accounted for, each as an insert or a skip depending on
    // whether this site has been imported into before. Which it is was asserted
    // here until the fixture stayed behind from an earlier run and the spec
    // started failing on the second pass - a green test that needed a fresh
    // database was testing the database, not the plan.
    cy.get('table tbody tr').should('have.length.greaterThan', 0);
    cy.get('table').contains('td', /insert|skip/).should('exist');

    cy.get('#toolbar').contains('Apply plan').click();

    messages({ timeout: 120000 })
      .should('match', /row\(s\) written/);

    // Planning again now finds the definitions present, and initialize leaves
    // them alone - which is the whole difference between the two modes.
    cy.get('#mode').select('initialize');
    cy.get('#toolbar').contains('Plan import').click();

    messages({ timeout: 60000 })
      .should('match', /0 to insert/);
  });

  it('applies a large import in slices, and says where it has got to', () => {
    // Everything the previous spec left on disk is still there, so this only
    // needs a fresh plan. Reset, because the fixture is already imported by
    // then and initialize would plan nothing to write.
    cy.get('#mode').select('reset');
    cy.get('#rows').clear().type('10');
    cy.get('#toolbar').contains('Plan import').click();

    messages({ timeout: 60000 })
      .should('match', /33 payloads/);

    cy.get('#rows').clear().type('10');
    cy.get('#toolbar').contains('Apply plan').click();

    // Ten of thirty-three written, so the run is open and says so. Pause first,
    // or the page advances itself out from under the assertions.
    cy.contains('#jcbinoutPause', 'Pause', { timeout: 60000 }).click();

    cy.contains('.card', 'Import under way').within(() => {
      cy.contains('10 of 33 operations').should('be.visible');
      cy.get('.progress-bar').should('contain.text', '30%');
    });

    // A run under way is the only thing on offer: planning a second import
    // over a half-written one would plan against a moving installation.
    cy.get('#toolbar').contains('Continue import').should('be.visible');
    cy.get('#toolbar').contains('Stop import').should('be.visible');
    cy.get('#toolbar').contains('Plan import').should('not.exist');

    cy.get('#toolbar').contains('Continue import').click();
    cy.contains('#jcbinoutPause', 'Pause', { timeout: 60000 }).click();
    cy.contains('.card', 'Import under way').should('contain.text', '20 of 33');

    // Let it finish by itself from here, which is what it does unattended.
    cy.get('#toolbar').contains('Continue import').click();

    messages({ timeout: 120000 })
      .should('match', /33 row\(s\) written/);

    // And the run is gone, so the ordinary import controls are back.
    cy.contains('.card', 'Import under way').should('not.exist');
    cy.get('#toolbar').contains('Plan import').should('be.visible');
  });

  it('exports what JCB actually holds, not just a blueprint on disk', () => {
    // The distinction this exists for: a repository is a blueprint somebody
    // has already pushed, so until there was this there was no way to export a
    // component still being built in JCB's own interface.
    cy.get('#toolbar').contains('Export installed').click();

    messages({ timeout: 120000 })
      .should('match', /Read \d+ row\(s\) across \d+ entity type\(s\)/);

    // The whole installation, so more than the 33 payloads of the fixture.
    messages()
      .then((text) => {
        const rows = Number(/Read (\d+) row/.exec(text)[1]);

        expect(rows).to.be.greaterThan(33);
      });

    // And a chunk is on disk afterwards, which is the point of running it.
    cy.get('#blueprint').closest('.card').within(() => {
      cy.contains('.badge', 'Exported').should('be.visible');
    });
  });

  it('validates the built language', () => {
    cy.get('#toolbar').contains('Validate').click();

    messages({ timeout: 60000 })
      .should('match', /valid LionWeb serialisation/i);
  });
});
