<?php

namespace Connector\Integrations\Salesforce;

class Config
{
    // Default API version. Can be overwritten by user in connector setup.
    // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/rest_rns.htm
    public const API_VERSION = '60.0';

    // Client ID and Secret, registered in FormAssembly's main Salesforce org as the "FormAssembly Salesforce Connector"
    public const CLIENT_ID = '3MVG9y6x0357Hleeqyr7zLW1Sw3VgxtTHRSt82P0oMvyn8YTuMWojlCRKah0iaZC2yeKDzVuJrf8bKu.wsw9V';
    public const CLIENT_SECRET = '6821270006328137189';

    // FormAssembly OAUth2 Redirect URL. Expected to be https://app.formassembly.com in production.
    // Individual customer instances are not whitelisted for OAuth2, but app.formassembly will forward the
    // request back to the current instance.
    public const REDIRECT_URI = 'https://app.formassembly.localhost:8443/api_v2/authorization/redirect';

    // Misc OAuth configuration.
    public const USER_INFO_URI = '';
    public const AUTH_URI      = '/services/oauth2/authorize';
    public const TOKEN_URI     = '/services/oauth2/token';
    public const SCOPES        = 'api refresh_token';
    public const BASE_URI      = 'https://login.salesforce.com';
}
