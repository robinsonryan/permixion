<?php

declare(strict_types=1);

use Composer\InstalledVersions;

/*
|--------------------------------------------------------------------------
| The Taxon this suite is actually running against
|--------------------------------------------------------------------------
|
| Every byte of this package's state lives in Taxon. Since 0.5.2 Taxon is
| consumed by tag from GitHub, like every other consumer, so the first assertion
| is the one that matters day to day: the installed release must sit inside the
| `require` constraint. The second only fires for a `-dev` install — someone
| pointing Composer at a local checkout while developing both packages — and
| guards the `options.versions` pin such a setup needs, which is how Taxon
| 0.4.0's tag-uniqueness hardening once ran under this suite unnoticed.
|
*/

/**
 * The compatibility series a version belongs to under Composer's caret rules:
 * below 1.0.0 a caret pins the minor, so `0.5.1` and `0.5.9` are the same series
 * and `0.6.0` is not.
 */
function compatibilitySeries(string $version): string
{
    $parts = explode('.', ltrim(trim($version), '^~v'));
    $major = $parts[0] ?? '';
    $minor = $parts[1] ?? '';

    return $major === '0' ? '0.'.$minor : $major;
}

/**
 * Every series the `require` constraint on Taxon accepts.
 *
 * @return list<string>
 */
function declaredTaxonSeries(): array
{
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);
    $constraint = is_array($manifest) ? $manifest['require']['robinsonryan/taxon'] ?? '' : '';

    $branches = preg_split('/\s*\|\|?\s*/', (string) $constraint) ?: [];

    return array_values(array_unique(array_map(compatibilitySeries(...), $branches)));
}

/**
 * The newest released version the installed Taxon's own changelog names, or null
 * when it has no changelog to read.
 */
function installedTaxonChangelogVersion(): ?string
{
    $path = InstalledVersions::getInstallPath('robinsonryan/taxon');
    $changelog = $path === null ? null : $path.'/CHANGELOG.md';

    if ($changelog === null || ! is_file($changelog)) {
        return null;
    }

    preg_match('/^##\s*\[(\d+\.\d+\.\d+)\]/m', (string) file_get_contents($changelog), $matches);

    return $matches[1] ?? null;
}

it('runs against a Taxon inside the constraint this package declares', function (): void {
    $installed = (string) InstalledVersions::getPrettyVersion('robinsonryan/taxon');
    $declared = declaredTaxonSeries();

    expect(compatibilitySeries($installed))->toBeIn(
        $declared,
        "Taxon is installed as {$installed}, which is outside the declared "
        .implode('|', $declared).'. If this is the local path repository, the '
        .'`options.versions` pin in composer.json has been left behind — bump it.'
    );
});

/*
 * The pin says which series the checkout holds. The checkout says so too, in its
 * own changelog, and a release that moved the checkout past the pin is invisible
 * to Composer — it reports the pin back verbatim. Comparing the two is the only
 * thing standing between a stale pin and another silent minor upgrade.
 *
 * A real tagged install carries no such fiction, so this only runs for the dev
 * alias a path repository produces.
 */
it('runs against a Taxon checkout whose own changelog agrees with the pinned version', function (): void {
    $installed = (string) InstalledVersions::getPrettyVersion('robinsonryan/taxon');

    if (! str_ends_with($installed, '-dev')) {
        expect(true)->toBeTrue();

        return;
    }

    $released = installedTaxonChangelogVersion();

    expect($released)->not->toBeNull('The Taxon path checkout has no CHANGELOG.md to check the pin against.')
        ->and(compatibilitySeries((string) $released))->toBe(
            compatibilitySeries($installed),
            "composer.json pins the Taxon path checkout at {$installed}, but that checkout's "
            ."changelog says its latest release is {$released}. The pin is stale, so this suite "
            .'is running newer Taxon code under an older version number — bump `options.versions` '
            .'and the `require` constraint together.'
        );
});
