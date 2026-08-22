<?php

declare(strict_types=1);

/**
 * Resolves one parsed external target to a verified public IP address.
 * RemoteContentInspector depends on this contract so DNS safety can be tested independently from HTTP responses.
 */
interface NetworkTargetResolver
{
    public function resolvePublicIp(LinkTarget $target): string;
}
