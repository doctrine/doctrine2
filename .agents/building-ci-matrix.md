# Building the CI build matrix

The matrix aims for high test coverage of the library with each supported dependency, while running only the jobs that test something no other job does.

## Process

1. Group the dimensions of the matrix by the code that depends on them. Two dimensions belong to the same group only if some code depends on both of them.
2. Select the values of each dimension by the rules below, and designate one of them as its baseline.
3. For each group, run every combination of the selected values of its dimensions that can run together, with each dimension outside the group at its baseline. Leave out a combination whose values within the group can't run together.
4. If a combination can't run with the baseline of a dimension outside the group, change as few such dimensions as possible. A version dimension (PHP, DBAL, server) takes the selected version closest to its baseline that the combination can run with. If only a dimension without versions would have to change, leave the combination out. Values within the group never change.
5. Run each resulting job once. All groups share the job that has every dimension at its baseline.

## Groups

1. **ORM runtime: PHP version × proxy implementation.** The ORM's own code depends on the PHP version, and its proxies depend on both. Neither depends on the database, so this group runs on SQLite with `pdo_sqlite`. Each job of this group runs the test suite twice: with and without the second-level cache.
2. **DBAL version × database.** The ORM adapts to the DBAL version, and the behavior of DBAL differs per database.
3. **Server version, per database.** The SQL that the ORM generates depends on the database and its server version, not on the driver.
4. **Driver × PHP version, per database.** Hydration depends on how the driver represents fetched values, and that depends on the driver and the PHP version, not on the server version.
5. **Composer dependencies.** The lowest dependencies run on the lowest supported PHP with `pdo_sqlite`. All other jobs use the highest dependencies.

## Values and baselines

### PHP versions

Select the lowest supported, the latest stable, nightly, and each intermediate version with a dedicated code path in the ORM, as determined by code coverage. In the driver × PHP group, select the lowest supported, the latest stable, and each version that changes how a driver represents fetched values. The baseline is the latest stable.

### Proxy implementations

Select every supported implementation. The baseline is the default.

### DBAL versions

Select the lowest supported, the highest stable, and the development branch of the highest major version. The development branch runs in addition to the highest stable version, not instead of it. The baseline is the highest stable.

### Server versions

1. The lowest supported version.
2. The highest version. A pre-release version runs in addition to the latest GA version, not instead of it.
3. Every LTS release that the vendor still supports, for vendors that designate LTS releases.
4. Intermediate versions with a dedicated code path in the ORM, as determined by code coverage. Prefer an LTS release over a non-LTS one, even if an older non-LTS release would trigger the same code path.

Code coverage doesn't reveal a server that changes behavior under unchanged ORM code. LTS releases are what most users run, so they get a job even without a dedicated code path. The baseline is the latest GA version.

SQLite has no server. Its version is the version of the SQLite library that PHP uses, which comes with the operating system of the runner. So the runner's operating system selects the SQLite version, and these rules apply to the operating system images.

### Databases

Select every supported database. The baseline is SQLite.

### Drivers

Select every driver of the database. The baseline is the PDO driver.
