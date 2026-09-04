<?php

declare(strict_types=1);

final readonly class LinkTarget
{
    /*
     * récupère les données et les stock dans un objet JSON 
     * pour les utiliser dans LinkNavigationPolicy et NetworkTargetValidator.
     */
    public function __construct(
        public string $originalUrl,
        public bool $internal,
        public string $scheme,
        public string $host,
        public string $path,
        public string $query,
    ) {
    }

    /*
     * Retourne true pour une route relative appartenant à l'application.
     * Cette valeur empêche le passage de la route dans NetworkTargetValidator et Guzzle.
     */
    public function isInternal(): bool
    {
        return $this->internal;
    }
}
