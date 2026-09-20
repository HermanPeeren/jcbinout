/**
 * The status view and the derive/build pipeline, against a real site.
 *
 * These specs exist because the PHPUnit suite has no Joomla in it. A component
 * whose classes are all individually correct can still render a blank page,
 * fail to find the JCB installed beside it, or write its artefacts somewhere
 * the web server cannot.
 */
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
    cy.get('joomla-alert, .alert', { timeout: 60000 })
      .invoke('text')
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

    cy.get('joomla-alert, .alert', { timeout: 60000 })
      .invoke('text')
      .should('match', /nodes:|concepts/);

    // The census table is what shows the language actually has shape.
    cy.contains('LionWeb language').should('be.visible');
    cy.contains('td', 'Concept').should('be.visible');
    cy.contains('td', 'Interface').should('be.visible');
  });

  it('validates the built language', () => {
    cy.get('#toolbar').contains('Validate').click();

    cy.get('joomla-alert, .alert', { timeout: 60000 })
      .invoke('text')
      .should('match', /valid LionWeb serialisation/i);
  });
});
