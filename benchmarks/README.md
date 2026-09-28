# Benchmarks

`run.sh` serves the Symfony skeleton of the tests (`tests/Fixtures/app`: Symfony 7.4, prod, debug
off) with PHP-FPM behind nginx and with swerve, without and with phasync-ext, and runs
`wrk -t4 -c64 -d10s` against each route. [results/](results/) has wrk's output.

- Machine: 2 × Intel Xeon E5-2697 v3 (56 threads), 62 GiB, Ubuntu; wrk on the same machine.
- PHP 8.5.11 with opcache (JIT off) for both; Symfony 7.4.19, Twig 3.30, swerve 0.1.0-alpha12,
  phasync 2.0.0-alpha11, phasync-ext 0.5.0-alpha8, nginx 1.24.
- 4 PHP-FPM children (`pm = static`) against `--workers=4`, swerve with `--no-access-log` and
  the default `kernels: 16`.
- Routes: `/json` (a `JsonResponse`); `/counter` (a session read and written on every request,
  through `SessionStorageFactory` and `PdoSessionHandler` on SQLite in WAL mode, the same
  configuration under both servers; under PHP-FPM each request opens the database anew, a swerve
  kernel keeps its connection); `/usleep?ms=10` (waits 10 ms in `usleep()`, as a database query
  would: without phasync-ext that blocks the worker).

The table is in the [README](../README.md#what-changes).
