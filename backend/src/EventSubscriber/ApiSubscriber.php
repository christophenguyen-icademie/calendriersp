<?php

namespace App\EventSubscriber;

use App\Security\Csrf;
use Doctrine\DBAL\Exception\{ForeignKeyConstraintViolationException, UniqueConstraintViolationException};
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\{ExceptionEvent, RequestEvent, ResponseEvent};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ApiSubscriber implements EventSubscriberInterface
{
    public function __construct(private Csrf $csrf) {}

    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['onRequest', 10], 'kernel.exception' => ['onException', -10], 'kernel.response' => 'onResponse'];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && str_starts_with($request->getPathInfo(), '/api/') && !$request->isMethodSafe() && !$this->csrf->isValid($request)) {
            $event->setResponse(new JsonResponse(['error' => 'Session expirée. Actualisez la page.', 'code' => 'INVALID_CSRF'], 403));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }
        $error = $event->getThrowable();
        $status = match (true) {
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            $error instanceof UniqueConstraintViolationException => 409,
            $error instanceof ForeignKeyConstraintViolationException => 409,
            $error instanceof \DomainException => 409,
            $error instanceof \InvalidArgumentException => 422,
            default => 500,
        };
        $message = match (true) {
            $status >= 500 && $status !== 503 => 'Erreur du serveur. Vérifiez la configuration et les migrations.',
            $error instanceof UniqueConstraintViolationException => str_contains($error->getMessage(), 'uniq_amicale_identifiant') ? 'Cet identifiant d’amicale est déjà utilisé. Choisissez-en un autre.' : 'Cet élément existe déjà.',
            $error instanceof ForeignKeyConstraintViolationException => 'Cet élément est encore utilisé par une autre entité. Actualisez les données pour consulter ses liens.',
            default => $error->getMessage(),
        };
        $event->setResponse(new JsonResponse(['error' => $message, 'code' => match ($status) {
            401 => 'AUTHENTICATION_REQUIRED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 409 => 'CONFLICT', 422, 400 => 'INVALID_INPUT', 503 => 'SOURCE_UNAVAILABLE', default => 'SERVER_ERROR',
        }], $status));
    }
}
