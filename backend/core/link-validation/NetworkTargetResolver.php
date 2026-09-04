<?php

declare(strict_types=1);

/**
 * Resolves one parsed external target to a verified public IP address.
 * RemoteContentInspector depends on this contract so DNS safety can be tested independently from HTTP responses.
 */
interface NetworkTargetResolver
{
    /*
     * Résout le host d'un LinkTarget externe et retourne une IP publique contrôlée.
     * Le contrat impose la validation de toutes les réponses DNS avant le retour de l'adresse épinglée par Guzzle.
     */
    public function resolvePublicIp(LinkTarget $target): string;
}
