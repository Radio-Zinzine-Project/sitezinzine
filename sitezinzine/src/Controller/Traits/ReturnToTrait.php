<?php
namespace App\Controller\Traits;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

trait ReturnToTrait
{
    public function storeReturnTo(Request $request, SessionInterface $session): void
    {
        $returnTo = $request->query->get('returnTo');
        
        // Sécurité : on accepte uniquement les URLs internes
        if ($returnTo && str_starts_with($returnTo, '/')) {
            $session->set('return_to_url', $returnTo);
        }
    }

    public function redirectToReturnTo(SessionInterface $session, UrlGeneratorInterface $urlGenerator, string $fallbackRoute, array $params = []): RedirectResponse
    {
        if ($session->has('return_to_url')) {
            $url = $session->get('return_to_url');
            $session->remove('return_to_url');
            return new RedirectResponse($url);
        }

        return new RedirectResponse($urlGenerator->generate($fallbackRoute, $params));
    }
}
