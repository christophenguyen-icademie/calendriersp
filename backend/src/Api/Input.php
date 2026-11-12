<?php

namespace App\Api;

use Symfony\Component\Uid\Uuid;

final class Input
{
    public static function text(array $data, string $key, int $max = 180): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
            throw new \InvalidArgumentException("Le champ $key est requis (1 à $max caractères).");
        }
        return trim($value);
    }

    public static function id(array $data, string $key): string
    {
        $value = self::text($data, $key, 36);
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException("Identifiant $key invalide.");
        }
        return $value;
    }

    public static function integer(array $data, string $key, int $min = 0, int $max = 100000): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException("Le champ $key doit être un entier entre $min et $max.");
        }
        return $value;
    }

    public static function date(array $data, string $key): \DateTimeImmutable
    {
        $value = self::text($data, $key, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Date $key invalide.");
        }
        return $date;
    }

    public static function choice(array $data, string $key, array $choices): string
    {
        $value = self::text($data, $key, 40);
        if (!in_array($value, $choices, true)) {
            throw new \InvalidArgumentException("Valeur $key invalide.");
        }
        return $value;
    }

    public static function password(array $data): string
    {
        $value = $data['password'] ?? null;
        if (!is_string($value) || strlen($value) < 12 || strlen($value) > 256) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir entre 12 et 256 caractères.');
        }
        return $value;
    }
}
