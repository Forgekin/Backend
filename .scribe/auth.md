# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {BEARER_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Obtain a token by calling one of the login endpoints (freelancer, employer, or admin). Then include it as `Authorization: Bearer {token}` in your requests.
