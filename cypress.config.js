import { defineConfig } from 'cypress';

/**
 * Cypress runs against a real Joomla with JcbInOut installed.
 *
 * There is no way around that: what these specs are for - that the status view
 * renders, that it reports the JCB it found, that Derive actually produces a
 * metamodel - needs the framework the component runs inside and a real JCB to
 * read. The PHPUnit suite deliberately has no Joomla in it, so it can say
 * nothing about any of that.
 *
 * The specs live under tests/ with everything else that tests something; this
 * config and cypress.env.json stay at the root, because that is where Cypress
 * looks for them.
 *
 * The site and the login come from `cypress.env.json`, which is git-ignored.
 * Copy `cypress.env.json.dist` and fill it in.
 */
export default defineConfig({
  e2e: {
    // Overridden by `baseUrl` in cypress.env.json.
    baseUrl: 'http://localhost/jcbinout/joomla',
    supportFile: 'tests/cypress/support/e2e.js',
    specPattern: 'tests/cypress/e2e/**/*.cy.js',
    video: false,
    screenshotOnRunFailure: true,

    // Joomla's admin is one origin and one session; nothing here talks to a
    // third party, so the browser need not police it.
    chromeWebSecurity: false,

    // Deriving the metamodel reads 51 entity types out of JCB by reflection.
    // It is fast, but not instant, and the default 4s is optimistic on a cold
    // opcache.
    defaultCommandTimeout: 30000,

    setupNodeEvents(on, config) {
      if (config.env.baseUrl) {
        config.baseUrl = config.env.baseUrl;
      }

      return config;
    },
  },
});
