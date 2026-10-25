# omnistate/openarchieven

Births, baptisms, marriages, deaths, burials and more for
[omniscient/omnistate](https://github.com/glitchr-studio/omnistate), from
[Open Archives](https://www.openarchieven.nl) (openarchieven.nl): the indexes of some two hundred
Dutch and Belgian archives in one search - civil registration, church books, population
registers, notarial deeds - plus what they index of Suriname and of France (INSEE's deaths). A
record names everyone in the act, says which archive keeps the original and under which
reference, and often links the scan of the act.

| Operation | Request |
|---|---|
| `search($query)` | `GET https://api.openarchieven.nl/1.1/records/search.json?name=…&eventtype=…&eventplace=…&country_code=…&number_show=…&start=…&lang=…` |
| `find('archive:uuid')` | `GET https://api.openarchieven.nl/1.1/records/show.json?archive=…&identifier=…&lang=…` |

The base address is the constant `OpenArchieven::URL` (or the `url` argument).

## Installation

```sh
composer require omniscient/omnistate omnistate/openarchieven
```

Requires PHP 8.2, `omniscient/omnistate` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a
Symfony application, `OmnistateBundle` registers it when it is installed (tag
`omnistate.civil_registry`); nothing to configure. The bundle builds it with the default label
language, `en`.

## Credentials

None: the API is open data, without an account or a key. It allows four calls a second per
address, a pace the class keeps itself.

## Options

| Argument | Type | Default | |
|---|---|---|---|
| `http` | `HttpClientInterface` | required | the client to call with |
| `timeout` | float | `10` | seconds the API has to answer |
| `lang` | string | `en` | the language of the labels (archive, source type, role): `en`, `nl`, `de`, `fr`; also the suffix of the record's page (none for `nl`) |
| `url` | string | `https://api.openarchieven.nl/1.1` | the API's base address |

## Capabilities

| | |
|---|---|
| Contract | `CivilRegistryInterface` (`name()`: `openarchieven`) |
| `countries()` | `['NL', 'BE', 'FR', 'SR']`: the values the API's country filter knows - the country of the archive that holds the record |
| `kinds()` | `BIRTH`, `BAPTISM`, `MARRIAGE`, `DEATH`, `BURIAL`, `OTHER` (population registers, notarial deeds, military records...) |
| `period()` | `null`: whatever each archive indexed, from the 16th century church books to the 20th century |
| `hasImages()` | `true`: the scans, given by `find()` when the archive scanned the act |
| `search()`, `find()` | yes |

`supports()` is true for a query with a name whose country is one of the four, or none.

## Behaviour

**A search.** From the `CivilQuery`:

| Query | Parameter | |
|---|---|---|
| `givenName`, `familyName` | `name` | with the year or the span of the event after it: `Coret 1853`, `Coret 1850-1860` |
| `dated`; else `died` for deaths and burials, `born` for births and baptisms (when kinds are asked) | in `name` | an open end is 1500 or the current year |
| one kind, no country | `eventtype` | in Dutch: `Geboorte`, `Doop`, `Huwelijk`, `Overlijden`, `Begraven` |
| `place` | `eventplace` | |
| `country` | `country_code` | `nl`, `be`, `fr`, `sr` (lower case) |
| `limit`, `page` | `number_show` (100 at most), `start` | `start` from the second page |
| - | `lang` | the `lang` argument |

Several kinds, or a kind with a country, are sorted out after the answer: the API drops its
country filter when `eventtype` is given with it (seen with `be` on 2026-10-04), so up to five
times the lines asked are fetched (100 at most) and those of the kinds wanted kept, up to the
limit. Pages are then pages of the unsorted answer.

**A line of a search** is one person of one act: the kind (from the event type, `OTHER` for any
other), the date and place of the event, the person's whole name (`CivilPerson::$fullName`) and
role, the archive (its name and code), the source type as `title`, the page, `raw`. Its
identifier is `<archive code>:<record id>`.

**A record.** `find()` takes `archive:uuid` (an archive code of 2 to 10 letters or digits, an id
of 8 to 60 characters; anything else is `null` without a request) and reads the A2A model: every
person with family name (its prefix included: `van Gogh`), given names, sex, role (in Dutch, as
the archive wrote it: `Kind`, `Vader`, `Moeder`, `Bruid`, `Bruidegom`, `Overledene`), birth date
and place, age, profession, whole name - the one the act is about first (`Kind`, `Dopeling`,
`Bruidegom`, `Bruid`, `Overledene`, `Geregistreerde`); the event's date (else the source's) and
place, with the source place's country name; the archive's institution, code, reference
(collection, book, folio, act number) and its own page (`SourceDigitalOriginal`); the act's number;
the scans (`SourceAvailableScans`); `url` `https://www.openarchieven.nl/<identifier>[/<lang>]`.

**Pace.** Every call waits until 0.26 s have passed since the previous one in the same PHP
process (`usleep()`), whatever the instance: four calls a second at most.

**Caching.** None of its own: the facade's cache keeps its answers.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnistate\Model\CivilQuery;
use Omnistate\Model\CivilRecordKind;
use Omnistate\Model\Period;
use Omnistate\OpenArchieven\OpenArchieven;
use Symfony\Component\HttpClient\HttpClient;

$registry = new OpenArchieven(HttpClient::create());   // labels in English; 'nl', 'de', 'fr' as third argument

$lines = $registry->search(new CivilQuery(familyName: 'van Gogh', givenName: 'Vincent Willem', dated: Period::year(1853), kinds: [CivilRecordKind::BIRTH], limit: 3));
foreach ($lines as $line) {
    printf("%s  %s %s, %s: %s (%s), %s\n", $line->identifier, $line->kind->value, $line->date, $line->place, $line->principal()->fullName, $line->principal()->role, $line->title);
}

$act = $registry->find($lines[0]->identifier);
printf("\n%s, %s, act %s\n", $act->title, $act->date, $act->number);
foreach ($act->persons as $person) {
    printf("  %-8s %-28s %s%s\n", $person->role, $person->name(), $person->sex->value, $person->age ? ', age '.$person->age : '');
}
printf("  %s\n  %s\n  %s\n", $act->archive, $act->url, $act->images[0] ?? 'no scan');
var_dump($registry->find('bhi:00000000-0000-0000-0000-000000000000'));
```

```
bhi:78a37833-d731-ffe5-184c-f9f424826871  birth 1853-03-30, Zundert: Vincent Willem van Gogh (Child), Civil registration births
wba:8759da72-f8d9-11df-a690-cd95c1e286e2  birth 1853-03-30, Zundert: Vincent Willem van Gogh (Child), Civil registration births

BS Geboorte, 1853-03-30, act 29
  Kind     Vincent Willem van Gogh      U
  Moeder   Anna Cornelia Carbentus      U
  Vader    Theodorus van Gogh           U
  Brabants Historisch Informatie Centrum, Bron: boek, Deel: 9509, Periode: 1853, Geboorteregister Zundert 1853, n° 29
  https://www.openarchieven.nl/bhi:78a37833-d731-ffe5-184c-f9f424826871/en
  https://images.memorix.nl/bhic/thumb/640x480/fab8c4f9-fa6f-cc20-c345-f5e21c1642d3.jpg
NULL
```

(run on 2026-10-25 in omnistate's Docker harness, against Open Archives. The act's persons carry
no sex in this answer: `U`, `Sex::UNKNOWN`. The same birth comes twice, indexed by two archives,
`bhi` and `wba`.)

Through the facade, with the other civil registers: `$omnistate->civilRecords($query)`, and
`$omnistate->civilRecord('openarchieven', $identifier)`.

## Errors

- HTTP 429: `UnavailableException` (`Open Archives answered 429.`), `retryAfter` from
  `Retry-After` (at least 1), else 1 s.
- HTTP 5xx: `UnavailableException`, no `retryAfter`.
- HTTP 401 or 403: `UnavailableException` (`Open Archives refused the call (403).`).
- No answer, a body that is not JSON: `UnavailableException` (`Open Archives did not answer: ...`).
- An `error_code` in the body (the API answers them with HTTP 200): `OmnistateException` on a
  search (`Open Archives refused the question (...)`), `null` on a `find()` (an archive or a
  record it does not have).
- HTTP 404 or 410 on `show.json`: the record is gone, `null`.

## Verified, and not

Tested offline: `Tests/OpenArchievenTest.php` (what it covers, the lines of a search, one kind
asked of the API and several sorted out here, the whole act, nothing found and a record that is
gone, an error in the body with HTTP 200, too many calls is not nobody, four calls a second at
most) and `Tests/OpenArchievenContractTest.php`, omnistate's conformance suite.

Against the real service on 2026-10-04: search by name and year, the five event types, the four
country codes and their sum (the filter is a partition by archive country), a place, a record's
details with its scan, an unknown record (410), an invalid archive (error in the body); the
fixtures in `Tests/Fixtures/` are its answers. Run again on 2026-10-25 (the example above).

## Responsibility

An independent, unofficial client, not affiliated with Open Archives (openarchieven.nl) or the
archives it publishes. Whoever runs it complies with the Open Archives API's terms ([Open Archives
API](https://www.openarchieven.nl/api/docs/)): see the README's Responsibility section.
