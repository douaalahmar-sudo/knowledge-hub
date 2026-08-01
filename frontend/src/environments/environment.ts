/**
 * PRODUCTION environment — this is the file the deployed Cloudflare build uses.
 *
 * Angular swaps in environment.development.ts for `ng serve` and the
 * development configuration (see `fileReplacements` in angular.json), so this
 * file must NOT point at localhost: in a deployed page `localhost` means the
 * *visitor's* machine, which is exactly why login failed with "Serveur
 * injoignable" for everyone but you. It must also be https, or the browser
 * blocks the call as mixed content on the https:// workers.dev page.
 *
 * >>> Replace the host below with your Render service URL once it exists. <<<
 * Keep the /api suffix — every route is registered under that prefix
 * (backend/routes/api.php), e.g. https://knowledge-hub-api.onrender.com/api
 */
export const environment =
{
    production: true,
    apiUrl: 'https://REPLACE-ME.onrender.com/api'
};
