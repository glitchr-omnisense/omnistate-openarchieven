<?php

namespace Omnistate\OpenArchieven;

use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Model\Archive;
use Omnistate\Model\CivilPerson;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\CivilRecord;
use Omnistate\Model\CivilRecordKind;
use Omnistate\Model\PartialDate;
use Omnistate\Model\Period;
use Omnistate\Model\Place;
use Omnistate\Model\Sex;
use Omnistate\Registry\CivilRegistryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Open Archives (openarchieven.nl): the indexes of some two hundred Dutch
 * and Belgian archives in one search - civil registration (births,
 * marriages, deaths), church books (baptisms, burials), population
 * registers, notarial deeds - plus what they index of Suriname and of
 * France (INSEE's deaths). A record names everyone in the act, says which
 * archive keeps the original and under which reference, and often links the
 * scan of the act.
 *
 * Free, no key, open data; four calls a second per address, kept here.
 * The API answers its errors in the body, with HTTP 200. Its country filter
 * is the country of the archive that holds the record, and knows four
 * values: nl, be, fr, sr.
 */
final class OpenArchieven implements CivilRegistryInterface, DeclaresUsagePolicyInterface
{
    public const URL = 'https://api.openarchieven.nl/1.1';

    /** The event types Open Archives filters on, in Dutch whatever the language asked. */
    private const EVENTS = [
        'Geboorte' => CivilRecordKind::BIRTH,
        'Doop' => CivilRecordKind::BAPTISM,
        'Huwelijk' => CivilRecordKind::MARRIAGE,
        'Overlijden' => CivilRecordKind::DEATH,
        'Begraven' => CivilRecordKind::BURIAL,
    ];

    /** Seconds between two calls: four a second at most. */
    private const PACE = 0.26;

    private static float $last = 0.0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly float $timeout = 10,
        /** The language of the labels (archive, source type, role): en, nl, de, fr. */
        private readonly string $lang = 'en',
        private readonly string $url = self::URL,
    ) {
    }

    /** Its usage policy: policy.json, beside this class. */
    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::fromFile(__DIR__.'/policy.json');
    }

    public function name(): string
    {
        return 'openarchieven';
    }

    /** The values its country filter knows; any other is ignored by the API, hence not declared. */
    public function countries(): array
    {
        return ['NL', 'BE', 'FR', 'SR'];
    }

    public function kinds(): array
    {
        return [CivilRecordKind::BIRTH, CivilRecordKind::BAPTISM, CivilRecordKind::MARRIAGE, CivilRecordKind::DEATH, CivilRecordKind::BURIAL, CivilRecordKind::OTHER];
    }

    public function period(): ?Period
    {
        return null;
    }

    public function hasImages(): bool
    {
        return true;
    }

    public function supports(CivilQuery $query): bool
    {
        return $query->hasName() && $query->inCountries(...$this->countries());
    }

    public function search(CivilQuery $query): array
    {
        // the year of the event goes with the name: "Coret 1853", "Coret 1850-1860"
        $when = $query->dated
            ?? ($query->wantsAny(CivilRecordKind::DEATH, CivilRecordKind::BURIAL) && [] !== $query->kinds ? $query->died : null)
            ?? ($query->wantsAny(CivilRecordKind::BIRTH, CivilRecordKind::BAPTISM) && [] !== $query->kinds ? $query->born : null);
        // One kind is asked of the API (eventtype), several are sorted out here.
        // With a country too, the kind is sorted out here as well: the API drops
        // its country filter when both are given (seen with "be", 2026-10-04) -
        // more lines are asked for, so that enough of the kind are left.
        $event = 1 === \count($query->kinds) ? array_search($query->kinds[0], self::EVENTS, true) : false;
        $number = $query->limit;
        if (null !== $query->country && [] !== $query->kinds) {
            $event = false;
            $number = min(100, $query->limit * 5);
        }

        $answer = $this->ask('/records/search.json', array_filter([
            'name' => trim($query->name().' '.self::years($when)),
            'eventtype' => $event ?: null,
            'eventplace' => $query->place,
            'country_code' => null === $query->country ? null : strtolower($query->country),
            'number_show' => $number,
            'start' => $query->page > 1 ? ($query->page - 1) * $number : null,
            'lang' => $this->lang,
        ], static fn (mixed $value) => null !== $value && '' !== $value));

        $docs = self::at($answer, 'response', 'docs') ?? [];
        if (!\is_array($docs)) {
            throw self::malformed();
        }
        $records = array_map(fn (mixed $doc) => $this->line(\is_array($doc) ? $doc : throw self::malformed()), $docs);

        return \array_slice(array_values(array_filter($records, static fn (CivilRecord $record) => $query->wants($record->kind))), 0, $query->limit);
    }

    /** $identifier is "archive:uuid", as a search gives it. */
    public function find(string $identifier): ?CivilRecord
    {
        if (!preg_match('/^([a-z0-9]{2,10}):([\w{}-]{8,60})$/i', $identifier, $match)) {
            return null;
        }
        $answer = $this->ask('/records/show.json', ['archive' => strtolower($match[1]), 'identifier' => $match[2], 'lang' => $this->lang], true);

        return null === $answer || !isset($answer['Source']) ? null : $this->act($identifier, $answer);
    }

    private static function years(?Period $period): string
    {
        $from = $period?->from?->year;
        $to = $period?->to?->year;
        if (null === $from && null === $to) {
            return '';
        }
        $from ??= 1500;
        $to ??= (int) date('Y');

        return $from === $to ? (string) $from : $from.'-'.$to;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<mixed>|null null: gone (find only)
     */
    private function ask(string $path, array $query, bool $find = false): ?array
    {
        $wait = self::$last + self::PACE - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        self::$last = microtime(true);

        try {
            $response = $this->http->request('GET', rtrim($this->url, '/').$path, ['query' => $query, 'headers' => ['Accept' => 'application/json'], 'timeout' => $this->timeout]);
            $status = $response->getStatusCode();
            if (429 === $status || $status >= 500) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? null;

                throw new UnavailableException(sprintf('Open Archives answered %d.', $status), 429 === $status ? (is_numeric($retry) ? max(1, (int) $retry) : 1) : null, null, $status);
            }
            if (401 === $status || 403 === $status) {
                throw new UnavailableException(sprintf('Open Archives refused the call (%d).', $status));
            }
            if ($find && \in_array($status, [404, 410], true)) {
                return null;
            }
            $body = $response->getContent(false);
            $answer = '' === trim($body) ? [] : json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            throw new UnavailableException('Open Archives did not answer: '.$e->getMessage(), null, $e);
        }
        if (null !== self::at($answer, 'error_code')) {
            if ($find) {
                return null;   // an archive or a record it does not have
            }
            throw new OmnistateException(sprintf('Open Archives refused the question (%s): %s', self::cast(self::at($answer, 'error_code')), self::cast(self::at($answer, 'error_description'))));
        }

        return null === $answer || \is_array($answer) ? $answer : throw self::malformed();
    }

    private static function kind(?string $event): CivilRecordKind
    {
        return self::EVENTS[$event ?? ''] ?? CivilRecordKind::OTHER;
    }

    /**
     * A line of a search: one person of one act.
     *
     * @param array<mixed> $doc
     */
    private function line(array $doc): CivilRecord
    {
        $place = $doc['eventplace'] ?? null;
        $place = \is_array($place) ? ($place[0] ?? null) : $place;
        $date = $doc['eventdate'] ?? [];
        $code = self::cast($doc['archive_code'] ?? null);

        return new CivilRecord(
            identifier: $code.':'.self::cast($doc['identifier'] ?? null),
            kind: self::kind(self::string($doc['_eventtype'] ?? $doc['eventtype'] ?? null)),
            source: $this->name(),
            date: PartialDate::of(self::integer(self::at($date, 'year')), self::integer(self::at($date, 'month')), self::integer(self::at($date, 'day'))),
            place: \is_string($place) && '' !== $place ? new Place($place) : null,
            persons: [new CivilPerson(role: self::string($doc['relationtype'] ?? null), fullName: self::string($doc['personname'] ?? null))],
            archive: new Archive(self::cast($doc['archive_org'] ?? $doc['archive'] ?? $code), $code),
            url: self::string($doc['url'] ?? null),
            title: self::string($doc['sourcetype'] ?? null),
            raw: $doc,
        );
    }

    /**
     * The whole act (the A2A model): everyone named, the reference, the scans.
     *
     * @param array<mixed> $a2a
     */
    private function act(string $identifier, array $a2a): CivilRecord
    {
        $list = static fn (mixed $value): array => \is_array($value) ? (array_is_list($value) ? $value : [$value]) : [];
        $event = $list($a2a['Event'] ?? null)[0] ?? [];
        $source = $a2a['Source'];

        $roles = [];
        foreach ($list($a2a['RelationEP'] ?? null) as $relation) {
            $roles[self::key(self::at($relation, 'PersonKeyRef'))] = self::at($relation, 'RelationType');
        }
        $kind = self::kind(self::string(self::at($event, 'EventType')));
        $persons = [];
        foreach ($list($a2a['Person'] ?? null) as $person) {
            $name = self::at($person, 'PersonName') ?? [];
            $given = trim(self::cast(self::at($name, 'PersonNameFirstName')));
            $age = self::at($person, 'Age', 'PersonAgeLiteral');
            $born = self::at($person, 'BirthPlace', 'Place');
            $persons[] = new CivilPerson(
                familyName: trim(self::cast(self::at($name, 'PersonNamePrefixLastName')).' '.self::cast(self::at($name, 'PersonNameLastName'))),
                givenNames: '' === $given ? [] : (preg_split('/\s+/', $given) ?: []),
                sex: Sex::fromAny(self::at($person, 'Gender')),
                role: self::string($roles[self::key(self::at($person, '@pid'))] ?? null),
                birthDate: self::date(self::object(self::at($person, 'BirthDate'))),
                birthPlace: null !== $born ? new Place(self::string($born)) : null,
                age: is_numeric($age) ? (int) $age : null,
                profession: self::string(self::at($person, 'Profession')),
                fullName: self::string(self::at($name, 'PersonNameLiteral')),
            );
        }
        // the one the act is about first: the child, the spouses, the deceased
        $main = ['Kind', 'Dopeling', 'Bruidegom', 'Bruid', 'Overledene', 'Geregistreerde'];
        usort($persons, static fn (CivilPerson $a, CivilPerson $b) => (\in_array($a->role, $main, true) ? 0 : 1) <=> (\in_array($b->role, $main, true) ? 0 : 1));

        $reference = self::at($source, 'SourceReference') ?? [];
        $images = [];
        foreach ($list(self::at($source, 'SourceAvailableScans', 'Scan')) as $scan) {
            $uri = self::at($scan, 'Uri');
            if (null !== $uri) {
                $images[] = self::cast($uri);
            }
        }
        $place = self::at($event, 'EventPlace', 'Place') ?? self::at($source, 'SourcePlace', 'Place');
        $folio = self::at($reference, 'Folio');
        $number = self::at($reference, 'DocumentNumber');
        [$archive] = explode(':', $identifier, 2);

        return new CivilRecord(
            identifier: $identifier,
            kind: $kind,
            source: $this->name(),
            date: self::date(self::object(self::at($event, 'EventDate'))) ?? self::date(self::object(self::at($source, 'SourceDate'))),
            place: null === $place ? null : new Place(self::string($place), countryName: self::string(self::at($source, 'SourcePlace', 'Country'))),
            persons: $persons,
            archive: new Archive(
                self::cast(self::at($reference, 'InstitutionName') ?? $archive),
                strtolower($archive),
                implode(', ', array_filter([self::cast(self::at($reference, 'Collection')), self::cast(self::at($reference, 'Book')), null !== $folio ? 'folio '.self::cast($folio) : null, null !== $number ? 'n° '.self::cast($number) : null])) ?: null,
                self::string(self::at($source, 'SourceDigitalOriginal')),
            ),
            url: 'https://www.openarchieven.nl/'.$identifier.('nl' === $this->lang ? '' : '/'.$this->lang),
            images: $images,
            title: self::string(self::at($source, 'SourceType')),
            number: null !== $number ? self::cast($number) : null,
            raw: $a2a,
        );
    }

    /** @param array<mixed>|null $date */
    private static function date(?array $date): ?PartialDate
    {
        return null === $date ? null : PartialDate::of(self::integer($date['Year'] ?? 0), self::integer($date['Month'] ?? 0), self::integer($date['Day'] ?? 0));
    }

    /**
     * A value handed to a ?string parameter, as PHP coerced it: a scalar as
     * its string, null kept; an array where a text belongs is an answer of
     * another shape.
     */
    private static function string(mixed $value): ?string
    {
        return match (true) {
            null === $value, \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? '1' : '',
            default => throw self::malformed(),
        };
    }

    /**
     * A date, as PHP handed it to an ?array parameter: null when absent;
     * anything else where an object belongs is an answer of another shape.
     *
     * @return array<mixed>|null
     */
    private static function object(mixed $value): ?array
    {
        return null === $value || \is_array($value) ? $value : throw self::malformed();
    }

    /**
     * A person's key as PHP used it as an array key ("" when absent, a float
     * or a bool as an integer); a list there is an answer of another shape.
     */
    private static function key(mixed $value): int|string
    {
        return match (true) {
            null === $value => '',
            \is_int($value), \is_string($value) => $value,
            \is_float($value), \is_bool($value) => (int) $value,
            default => throw self::malformed(),
        };
    }

    /**
     * $value[$key][...] ?? null, as PHP reads it: an array's item, a string's
     * character at an integer offset, null for anything else.
     */
    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (\is_array($value) || (\is_string($value) && \is_int($key))) {
                $value = $value[$key] ?? null;
            } else {
                return null;
            }
        }

        return $value;
    }

    /** (string) of a scalar, as the answer's values were cast: "" for null, and for an array where PHP gave "Array". */
    private static function cast(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** (int) of a scalar, 0 for anything else (an array: where PHP gave 1 for a non-empty one). */
    private static function integer(mixed $value): int
    {
        return \is_scalar($value) ? (int) $value : 0;
    }

    /** The family's rule on a body that is not what it should be (docs/providers.md). */
    private static function malformed(): UnavailableException
    {
        return new UnavailableException('Open Archives answered in another shape than its API\'s.');
    }
}
