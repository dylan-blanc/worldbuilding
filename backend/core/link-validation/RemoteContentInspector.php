<?php

declare(strict_types=1);

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;

/**
 * Inspects external HTTPS navigation without downloading or classifying the linked file.
 * SafeLinkValidator delegates redirects, SSRF checks and forced-download headers here before CMS insertion or publication.
 */
final class RemoteContentInspector
{
    private const MAX_REDIRECTS = 3;
    private const FORCED_DOWNLOAD_MIME_TYPE = "application/octet-stream";

    /*
     * Dépendances du contrôle distant : Guzzle pour les en-têtes HTTP, LinkTargetParser pour les redirections,
     * LinkNavigationPolicy pour les extensions interdites et NetworkTargetResolver pour la protection SSRF.
     */
    public function __construct(
        private ClientInterface $client,
        private LinkTargetParser $parser,
        private LinkNavigationPolicy $navigationPolicy,
        private NetworkTargetResolver $networkValidator,
    ) {
    }

    /*
     * Entrée : URL HTTPS externe produite par SafeLinkValidator.
     * Chaque destination passe par les règles d'extension, la résolution DNS et le contrôle des IP publiques avant
     * la requête Guzzle. Une redirection Location est analysée comme une nouvelle URL et la limite totale est de trois.
     * Une réponse finale 2xx transmet uniquement Content-Type et Content-Disposition à inspectFinalResponse().
     */
    public function inspect(LinkTarget $initialTarget): LinkValidationResult
    {
        $currentTarget = $initialTarget;

        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $this->navigationPolicy->assertAllowed($currentTarget);
                $ip = $this->networkValidator->resolvePublicIp($currentTarget);
                $response = $this->requestAsync($currentTarget, $ip)->wait();

                if (!$response instanceof ResponseInterface) {
                    throw new RuntimeException("La reponse HTTP distante est invalide");
                }

                $status = $response->getStatusCode();

                if ($status >= 300 && $status < 400) {
                    $location = trim($response->getHeaderLine("Location"));
                    $response->getBody()->close();

                    if ($location === "" || $hop === self::MAX_REDIRECTS) {
                        throw new DomainException("La limite de 3 redirections a ete depassee");
                    }

                    $redirectUrl = (string) UriResolver::resolve(
                        new Uri($currentTarget->originalUrl),
                        new Uri($location),
                    );
                    $currentTarget = $this->parser->parse($redirectUrl);

                    if ($currentTarget->isInternal()) {
                        throw new DomainException("Une redirection externe ne peut pas cibler une route interne");
                    }

                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    $response->getBody()->close();
                    throw new DomainException("Un ou plusieurs liens sont invalides");
                }

                $declaredMime = strtolower(trim(explode(";", $response->getHeaderLine("Content-Type"))[0]));
                $contentDisposition = $response->getHeaderLine("Content-Disposition");
                $response->getBody()->close();

                return $this->inspectFinalResponse(
                    $initialTarget,
                    $declaredMime,
                    $contentDisposition,
                );
            }
        } catch (Throwable $exception) {
            return LinkValidationResult::rejected(
                $initialTarget->originalUrl,
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : "La verification du lien externe a echoue",
            );
        }

        throw new LogicException("Inspection de lien incomplete");
    }

    /*
     * Bloque une réponse dont Content-Disposition impose un téléchargement ou dont le MIME vaut application/octet-stream.
     * Toute autre réponse 2xx est autorisée sans lecture, détection ni conservation de son contenu.
     */
    private function inspectFinalResponse(
        LinkTarget $initialTarget,
        string $declaredMime,
        string $contentDisposition,
    ): LinkValidationResult {
        if ($this->forcesDownload($contentDisposition)) {
            return LinkValidationResult::rejected(
                $initialTarget->originalUrl,
                "Le site distant impose le telechargement de cette ressource",
            );
        }

        if ($declaredMime === self::FORCED_DOWNLOAD_MIME_TYPE) {
            return LinkValidationResult::rejected(
                $initialTarget->originalUrl,
                "Le site distant retourne un fichier a telecharger",
            );
        }

        return new LinkValidationResult(
            true,
            $initialTarget->originalUrl,
            "Navigation externe autorisee",
        );
    }

    /*
     * Retourne true lorsque Content-Disposition est présent et ne commence pas par inline.
     * Les paramètres placés après ;, comme filename, sont exclus de la comparaison.
     */
    private function forcesDownload(string $header): bool
    {
        if (trim($header) === "") {
            return false;
        }

        $type = strtolower(trim(explode(";", $header, 2)[0]));

        return $type !== "inline";
    }

    /*
     * Démarre un GET HTTPS asynchrone sans télécharger le corps dans la mémoire de l'application.
     * CURLOPT_RESOLVE épingle l'IP contrôlée, Guzzle ne suit aucune redirection et Range limite la réponse demandée
     * au premier octet. Le flux retourné est fermé par inspect() immédiatement après la lecture des en-têtes.
     */
    private function requestAsync(LinkTarget $target, string $ip): PromiseInterface
    {
        if (!defined("CURLOPT_RESOLVE")) {
            throw new RuntimeException("Le transport HTTP securise est indisponible");
        }

        $resolvedIp = str_contains($ip, ":") ? "[" . $ip . "]" : $ip;
        $resolvedHost = str_contains($target->host, ":") ? "[" . $target->host . "]" : $target->host;

        return $this->client->requestAsync("GET", $target->originalUrl, [
            "allow_redirects" => false,
            "http_errors" => false,
            "connect_timeout" => 3,
            "timeout" => 6,
            "verify" => true,
            "proxy" => "",
            "headers" => [
                "Accept" => "*/*",
                "Accept-Encoding" => "identity",
                "Range" => "bytes=0-0",
                "User-Agent" => "Worldbuilding-SafeLink/1.0",
            ],
            "stream" => true,
            "curl" => [
                CURLOPT_RESOLVE => [$resolvedHost . ":443:" . $resolvedIp],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
            ],
        ]);
    }
}
