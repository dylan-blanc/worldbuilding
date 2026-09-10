<?php

declare(strict_types=1);

use GuzzleHttp\Client;

/**
 * Provides the single safe-link facade shared by the CMS link dialog, draft validation and publication.
 * Tiptap insertion and publication use REMOTE checks, while draft saves use LOCAL checks without network latency.
 */
final class SafeLinkValidator
{
    /*
     * Dépendances du flux : LinkTargetParser pour la structure de l'URL, LinkNavigationPolicy pour le chemin,
     * RemoteContentInspector pour DNS, redirections et en-têtes HTTP. Les tests injectent un client réseau simulé.
     */
    public function __construct(
        private LinkTargetParser $parser,
        private LinkNavigationPolicy $navigationPolicy,
        private RemoteContentInspector $remoteInspector,
    ) {
    }

    /*
     * défini la configuration de GuzzleHTTP utiliser par LinkController et CmsContentValidator.
     * analyse le premier lien et recupère les informations du header http pour définir s'il est autorisé ou non.
     * 3 essais de connexion, 6 secondes de timeout, pas de redirection automatique
     * .
     */
    public static function createDefault(): self
    {
        $parser = new LinkTargetParser();
        $navigationPolicy = new LinkNavigationPolicy();
        $networkValidator = new NetworkTargetValidator();
        $client = new Client([
            "allow_redirects" => false, 
            "connect_timeout" => 3,
            "timeout" => 6,
            "http_errors" => false,
            // vérifie que le certificat SSL est valide et que le nom d'hôte correspond au certificat.
            // verifie le https:// et le certificat SSL pour les liens https://, sinon renvoie une erreur.
            "verify" => true,
        ]);

        return new self(
            $parser,
            $navigationPolicy,
            new RemoteContentInspector(
                $client,
                $parser,
                $navigationPolicy,
                $networkValidator,
            ),
        );
    }

    /*
     * Entrée : URL utilisateur et niveau LOCAL ou REMOTE.
     * LOCAL : parse() puis règles de chemin, sans connexion réseau (liens interne provenant de l'application)
     * n'utilise pas guzzlehttp mais uniquement LinkNavigationPolicy pour vérifier les extensions de fichiers et les routes internes.
     * 
     * 
     * REMOTE : parse() puis inspection DNS, SSRF, redirections et en-têtes de téléchargement (liens externes à l'application)
     * utilisation de guzzlehttp pour récupérer les en-têtes http et vérifier le type de contenu et le content-disposition. (download?)
     * 
     * Sortie : LinkValidationResult positif ou négatif
     * 
     */
    public function validate(string $url, LinkValidationMode $mode): LinkValidationResult
    {
        try {
            $target = $this->parser->parse($url);

            if ($target->isInternal() || $mode === LinkValidationMode::LOCAL) {
                $this->navigationPolicy->assertAllowed($target);

                return LinkValidationResult::acceptedLocally($target);
            }

            return $this->remoteInspector->inspect($target);
        } catch (Throwable $exception) {
            return LinkValidationResult::rejected(
                $url,
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : "La verification du lien a echoue",
            );
        }
    }
}
