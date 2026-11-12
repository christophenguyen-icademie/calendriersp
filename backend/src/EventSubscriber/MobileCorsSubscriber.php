<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\{RequestEvent, ResponseEvent};

/** Native requests have no Origin; Expo web origins must be explicitly permitted. */
final class MobileCorsSubscriber implements EventSubscriberInterface
{
    public function __construct(private string $allowedOrigins) {}
    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['onRequest', 256], 'kernel.response' => ['onResponse', -20]];
    }
    private function allowed(string $origin): bool
    {
        return in_array($origin, array_filter(array_map('trim', explode(',', $this->allowedOrigins))), true);
    }
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) { return; }
        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === $request->getSchemeAndHttpHost()) { return; }
        if (!$this->allowed($origin)) {
            $event->setResponse(new JsonResponse(['error' => 'Origine web non autorisée.', 'code' => 'FORBIDDEN'], 403));
            return;
        }
        if ($request->isMethod('OPTIONS')) {
            $method = $request->headers->get('Access-Control-Request-Method', '');
            $headers = array_filter(array_map('trim', explode(',', strtolower($request->headers->get('Access-Control-Request-Headers', '')))));
            if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
                || array_diff($headers, ['accept', 'content-type', 'x-csrf-token'])) {
                $event->setResponse(new JsonResponse(['error' => 'Prévol web non autorisé.', 'code' => 'FORBIDDEN'], 403));
                return;
            }
            $event->setResponse(new Response('', 204));
        }
    }
    public function onResponse(ResponseEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) { return; }
        $response = $event->getResponse();
        $response->setVary('Origin', false);
        $origin = $event->getRequest()->headers->get('Origin');
        if ($origin === null || !$this->allowed($origin)) { return; }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE');
        $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type, X-CSRF-Token');
    }
}
