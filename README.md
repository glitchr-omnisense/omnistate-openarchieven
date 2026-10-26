# omnistate/openarchieven

Births, baptisms, marriages, deaths, burials and more from the indexes of some two hundred Dutch and
Belgian archives, through [Open Archives](https://www.openarchieven.nl), for
[omnisense/omnistate](https://github.com/glitchr-omnisense/omnistate).

```php
$registry = new OpenArchieven($httpClient);
$lines = $registry->search(new CivilQuery(familyName: 'van Gogh', givenName: 'Vincent Willem', dated: Period::year(1853), kinds: [CivilRecordKind::BIRTH]));
$act = $registry->find($lines[0]->identifier);      // "bhi:78a37833-…"
$act->persons;            // the child, the mother, the father - each with its role
$act->archive;            // Brabants Historisch Informatie Centrum, Geboorteregister Zundert 1853, n° 29
$act->images;             // the scan of the act
```

No framework needed: the package requires `omnisense/omnistate` and `symfony/http-client`.
`$httpClient` is the HTTP client to call with - the application's, `HttpClient::create()` in
plain PHP, a `MockHttpClient` in a test.

| | |
|---|---|
| Countries | NL, BE, FR (INSEE's deaths), SR - the country of the archive that holds the record |
| Kinds | births, baptisms, marriages, deaths, burials; everything else (population registers, notarial deeds, military records...) comes as OTHER |
| Years | whatever each archive indexed: from the 16th century church books to the 20th century |
| Picture of the act | yes, when the archive scanned it (`CivilRecord::$images`, given by `find()`) |
| Access | free, no key, open data; 4 calls a second per address (kept by the class) |

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Open Archives (openarchieven.nl) or the archives it publishes. It reaches their service
on behalf of whoever runs it, and it does not redistribute their data beyond the short excerpts
recorded as test fixtures. Its MIT licence covers this package's code only and grants no right to
the service or to its data.

Whoever installs and runs this package is responsible for complying with the Open Archives API's
terms ([Open Archives API](https://www.openarchieven.nl/api/docs/)), for obtaining whatever they
require (an account, a key, a licence or subscription, an attribution or a citation, a rate limit to
respect), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

Documentation: [docs/](docs/index.md). License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
