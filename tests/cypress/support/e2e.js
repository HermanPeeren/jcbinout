/**
 * Shared setup for the end-to-end specs.
 */

/**
 * Log into Joomla's administrator.
 *
 * The session is cached between specs: logging in is not what is under test,
 * and doing it once per spec turns a fast suite into a slow one.
 */
Cypress.Commands.add('adminLogin', () => {
  const user = Cypress.env('adminUser');
  const pass = Cypress.env('adminPassword');

  cy.session([user, pass], () => {
    cy.visit('/administrator/index.php');

    cy.get('#mod-login-username').type(user);
    cy.get('#mod-login-password').type(pass, { log: false });
    cy.get('#btn-login-submit').click();

    // Joomla 6 lands on administrator/index.php with no query string, so the
    // proof that the session took is the admin chrome being there and the
    // login form being gone - not anything in the URL.
    cy.get('#sidebarmenu, .header-items, joomla-core-loader', { timeout: 30000 })
      .should('exist');
    cy.get('#mod-login-username').should('not.exist');
  });
});

/**
 * Joomla's admin greets a fresh install with a guided-tour modal and a
 * statistics prompt. Both sit over the toolbar, so a click that should hit a
 * button hits a backdrop instead and the spec fails for a reason that has
 * nothing to do with the component.
 */
Cypress.Commands.add('dismissJoomlaPrompts', () => {
  cy.get('body').then(($body) => {
    if ($body.find('button:contains("Hide Forever")').length) {
      cy.contains('button', 'Hide Forever').click({ force: true });
    }
  });

  // The statistics prompt is a panel, not a dialog with a stable id; its
  // heading is the reliable handle.
  cy.get('body').then(($body) => {
    if ($body.text().includes('Enable Joomla Statistics?')) {
      cy.contains('button, a', /^No$/).click({ force: true });
    }
  });
});

/** Open a JcbInOut view. */
Cypress.Commands.add('openJcbInOut', (view = 'metamodel') => {
  cy.adminLogin();
  cy.visit(`/administrator/index.php?option=com_jcbinout&view=${view}`);
  cy.dismissJoomlaPrompts();
});

// Joomla's admin templates throw the odd uncaught exception from third-party
// JavaScript that has nothing to do with this component. Failing a spec on
// those would make the suite report the template's problems as ours.
Cypress.on('uncaught:exception', () => false);
