<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Cron;

use Modracx\FrontendDevTools\Model\Storage\ProfileRepository;

/**
 * Keeps stored runs inside the retention window.
 *
 * A profile holds the full query log of a request. Left alone on a busy development site
 * that is a table nobody notices until it is the largest one in the database.
 */
class PruneProfiles
{
    public function __construct(
        private readonly ProfileRepository $repository
    ) {
    }

    public function execute(): void
    {
        $this->repository->prune();
    }
}
