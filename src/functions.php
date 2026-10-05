<?php

namespace Swerve\Symfony;

use Swerve\RequestHandler;

/**
 * The entry point of the `symfony` swerve adapter, which swerve's workers call once each, after
 * the fork, with the application directory (see the `extra.swerve` section of this package's
 * composer.json).
 *
 * `$appDir/public/index.php` is the front controller: Symfony Runtime's own, unchanged, so the
 * same application still runs under PHP-FPM.
 *
 * @param string $appDir the application directory: where swerve was started
 */
function entry(string $appDir): RequestHandler
{
    $handler = new Handler($appDir);

    return new RequestHandler($handler->handle(...));
}
