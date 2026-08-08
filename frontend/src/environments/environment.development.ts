/**
 * DEV-ONLY environment — swapped in for `ng serve` via fileReplacements in
 * angular.json. Never used by the production build (see environment.ts,
 * which the Cloudflare auto-deploy reads unmodified), so pointing this at
 * localhost has no effect on the deployed site.
 */
export const environment =
{
    production: false,
    apiUrl: 'http://localhost:8000/api'
};